<?php

namespace Lampwick\Laravel\Transport;

use Lampwick\Agent\Client;

/**
 * The connection the instrumentation (laravel/nightwatch) uses to hand records to the local
 * agent, replacing its blocking socket.
 *
 * The instrumentation writes the whole frame and then reads the 4-byte answer with plain
 * fwrite()/fread() calls that have no deadline: an agent that accepts but never reads (paused,
 * stuck, or something else on the port) would hang the request, job or command. This is a
 * `lampwick-agent://` stream that only buffers writes; the first read sends the frame through
 * Lampwick\Agent\Client, which is non-blocking with one deadline for connect, write and answer,
 * and returns `2:OK` when the agent accepted it or nothing when it did not (the instrumentation
 * then reports an unexpected answer and drops the records, as it does when the agent is down).
 *
 * After the agent failed (unreachable, not reading, not answering), frames for it are dropped
 * at once for `$backoff` seconds, so a long-running command, queue worker or Octane server
 * loses one timeout per backoff period instead of one per batch.
 */
final class AgentStream
{
    public const SCHEME = 'lampwick-agent';

    /** The reason the last frame was not delivered, for diagnostics. */
    public static ?string $lastError = null;

    /** Seconds to stop trying an agent after it failed, so a stuck agent costs one timeout, not one per batch. */
    public static float $backoff = 10.0;

    /** @var array<string, float> per address: don't try before this time */
    private static array $downUntil = [];

    /** @var array<string, array{float, int}> timeout and frame limit per address */
    private static array $limits = [];

    /** @var resource|null set by PHP for stream wrappers */
    public $context;

    private string $address = '';

    private string $out = '';

    private ?string $answer = null;

    /**
     * The instrumentation's stream factory: `(string $address, float $connectionTimeout)` → stream.
     *
     * @return \Closure(string, float): resource
     */
    public static function factory(float $timeout, int $maxFrameBytes): \Closure
    {
        if (! in_array(self::SCHEME, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::SCHEME, self::class);
        }

        return static function (string $address, float $connectionTimeout) use ($timeout, $maxFrameBytes) {
            $address = (string) preg_replace('#^tcp://#', '', $address);
            self::$limits[$address] = [$timeout, $maxFrameBytes];
            $stream = fopen(self::SCHEME.'://'.$address, 'r+');
            if ($stream === false) {
                throw new \RuntimeException("Failed opening the agent stream for {$address}");
            }

            return $stream;
        };
    }

    /** Forget failed agents (tests, or after starting the agent in the same process). */
    public static function reset(): void
    {
        self::$downUntil = [];
        self::$lastError = null;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->address = substr($path, strlen(self::SCHEME.'://'));

        return $this->address !== '';
    }

    public function stream_write(string $data): int
    {
        $this->out .= $data;

        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        if ($this->answer === null) {
            $ok = false;
            if (($until = self::$downUntil[$this->address] ?? 0.0) > microtime(true)) {
                // The records are dropped, as they are while the agent is not running.
                self::$lastError = sprintf('the agent on %s failed; retrying in %.0f s', $this->address, $until - microtime(true));
            } else {
                [$timeout, $maxFrameBytes] = self::$limits[$this->address] ?? [1.0, 8 * 1024 * 1024];
                // The token hash comes from the frame itself; sendFrame() uses it.
                $client = new Client($this->address, '', $timeout, $maxFrameBytes);
                try {
                    $ok = $client->sendFrame($this->out);
                    self::$lastError = $client->lastError;
                    $failed = $client->agentFailed;
                } catch (\Throwable $e) {
                    self::$lastError = $e->getMessage();
                    $failed = true;
                }
                if ($failed) {
                    self::$downUntil[$this->address] = microtime(true) + self::$backoff;
                } else {
                    unset(self::$downUntil[$this->address]);
                }
            }
            $this->out = '';
            $this->answer = $ok ? '2:OK' : '';
        }
        $chunk = substr($this->answer, 0, $count);
        $this->answer = substr($this->answer, strlen($chunk));

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->answer === '';
    }

    /** stream_set_timeout() and friends: the deadline is the Client's. */
    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
        $this->out = '';
    }
}
