<?php

namespace Lampwick\Laravel\Agent;

use Closure;

/**
 * Local forwarder: accepts frames from the app on a local TCP address, answers `2:OK` as soon
 * as a frame is complete, and forwards the records in batches over HTTPS.
 *
 * Single-threaded event loop: stream_select() for the listening socket and app connections,
 * curl_multi for the outgoing request. Call `tick()` in a loop, `stop()` to begin a graceful
 * shutdown and `drain()` to flush what is left.
 */
final class Agent
{
    private const IDLE_TIMEOUT = 10.0;

    private const FRAME_TIMEOUT = 30.0;

    private const SUMMARY_INTERVAL = 300.0;

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{stream: resource, frame: Frame, opened: float, active: float}> */
    private array $connections = [];

    private bool $stopping = false;

    /** @var array<string, array{count: int, last: float}> */
    private array $warnings = [];

    /** @var array{frames: int, sent: int, requests: int, bytes: int, failures: int} */
    private array $stats = ['frames' => 0, 'sent' => 0, 'requests' => 0, 'bytes' => 0, 'failures' => 0];

    private float $lastSummary;

    private ?Batch $sending = null;

    private bool $wasFailing = false;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  Closure(string $level, string $message): void  $log
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        private readonly string $listen,
        private readonly Outbox $outbox,
        private readonly Sender $sender,
        private readonly ?string $tokenHash,
        private readonly int $maxFrame,
        private readonly int $maxConnections,
        private readonly Closure $log,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->lastSummary = ($this->clock)();
    }

    /** The 7-character token hash the app puts in its frames. */
    public static function tokenHash(string $token): string
    {
        return substr(hash('xxh128', $token), 0, 7);
    }

    public function listen(): string
    {
        $server = @stream_socket_server('tcp://'.$this->listen, $errno, $error);
        if ($server === false) {
            throw new \RuntimeException("cannot listen on {$this->listen}: {$error}");
        }
        stream_set_blocking($server, false);
        $this->server = $server;

        return (string) stream_socket_get_name($server, false);
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function stopping(): bool
    {
        return $this->stopping;
    }

    /** Runs until `stop()`, then drains for at most `$shutdownTimeout` seconds. */
    public function run(float $shutdownTimeout): void
    {
        while (! $this->stopping) {
            $this->tick(1.0);
        }
        $this->drain($shutdownTimeout);
    }

    /**
     * Stops accepting connections, finishes the open ones and sends everything buffered.
     * Returns true when nothing was left behind.
     */
    public function drain(float $timeout): bool
    {
        $this->stopping = true;
        if ($this->server !== null) {
            fclose($this->server);
            $this->server = null;
        }

        $deadline = ($this->clock)() + $timeout;
        while ((! $this->outbox->isEmpty() || $this->connections !== []) && ($this->clock)() < $deadline) {
            if (! $this->sender->busy() && $this->outbox->backoffUntil() > $deadline && $this->connections === []) {
                break; // lampwick asked us to wait longer than we have
            }
            $this->tick(0.05, draining: true);
        }

        $left = $this->outbox->bytes() + ($this->sending?->bytes() ?? 0);
        $this->summary(force: true);
        if ($left > 0) {
            $this->log('warning', sprintf('exiting with %s of records not sent', self::kb($left)));

            return false;
        }

        return true;
    }

    /** One pass of the event loop, waiting at most `$maxWait` seconds for activity. */
    public function tick(float $maxWait, bool $draining = false): void
    {
        $this->pollSender();
        $this->send($draining);

        $read = [];
        if ($this->server !== null) {
            $read[] = $this->server;
        }
        foreach ($this->connections as $connection) {
            $read[] = $connection['stream'];
        }

        $now = ($this->clock)();
        $wait = $maxWait;
        if ($this->sender->busy()) {
            $wait = min($wait, 0.01);
        } elseif (($due = $this->outbox->wait($now)) !== null) {
            $wait = min($wait, $draining ? 0.0 : $due);
        }

        if ($read === []) {
            usleep((int) ($wait * 1_000_000));
        } else {
            $write = $except = null;
            $seconds = (int) $wait;
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1_000_000));
            if ($ready > 0) {
                foreach ($read as $stream) {
                    $stream === $this->server ? $this->accept() : $this->receive($stream);
                }
            }
        }

        $this->expire();
        $this->pollSender();
        $this->send($draining);
        $this->summary();
    }

    private function accept(): void
    {
        while ($this->server !== null && ($stream = @stream_socket_accept($this->server, 0)) !== false) {
            if (count($this->connections) >= $this->maxConnections) {
                fclose($stream);
                $this->warn('connections', fn (int $n) => "too many concurrent connections from the app; dropped {$n}");

                continue;
            }
            stream_set_blocking($stream, false);
            $now = ($this->clock)();
            $this->connections[(int) $stream] = ['stream' => $stream, 'frame' => new Frame($this->maxFrame), 'opened' => $now, 'active' => $now];
        }
    }

    /** @param resource $stream */
    private function receive($stream): void
    {
        $id = (int) $stream;
        $connection = &$this->connections[$id];
        $bytes = @fread($stream, 65536);

        if ($bytes === false || ($bytes === '' && feof($stream))) {
            $this->close($id);

            return;
        }
        if ($bytes === '') {
            return;
        }
        $connection['active'] = ($this->clock)();

        try {
            $body = $connection['frame']->feed($bytes);
        } catch (FrameException $e) {
            $this->warn('frame:'.$e->getMessage(), fn (int $n) => "rejected {$n} payload(s) from the app: {$e->getMessage()}");
            $this->close($id);

            return;
        }

        $hash = $connection['frame']->hash;
        if ($hash !== null && $this->tokenHash !== null && ! hash_equals($this->tokenHash, $hash)) {
            $this->warn('hash', fn (int $n) => "rejected {$n} payload(s) whose token does not match LAMPWICK_TOKEN; does the app use the same .env as the agent?");
            $this->close($id);

            return;
        }

        if ($body === null) {
            return;
        }

        @fwrite($stream, '2:OK');
        $this->close($id);
        $this->stats['frames']++;

        if ($body === 'PING') {
            return;
        }
        if (! self::isJsonArray($body)) {
            $this->warn('json', fn (int $n) => "dropped {$n} payload(s) from the app that are not a JSON array");

            return;
        }

        $dropped = $this->outbox->droppedPayloads;
        $this->outbox->push($body, ($this->clock)());
        if ($this->outbox->droppedPayloads > $dropped) {
            $this->warn('buffer', fn (int $n) => "buffer full (LAMPWICK_AGENT_BUFFER_BYTES), dropped the oldest {$n} payload(s)", $this->outbox->droppedPayloads - $dropped);
        }
    }

    private function close(int $id): void
    {
        if (isset($this->connections[$id])) {
            @fclose($this->connections[$id]['stream']);
            unset($this->connections[$id]);
        }
    }

    private function expire(): void
    {
        $now = ($this->clock)();
        foreach ($this->connections as $id => $connection) {
            if ($now - $connection['active'] > self::IDLE_TIMEOUT || $now - $connection['opened'] > self::FRAME_TIMEOUT) {
                $this->close($id);
            }
        }
    }

    private function send(bool $draining): void
    {
        $now = ($this->clock)();
        if ($this->sender->busy() || ! $this->outbox->due($now, $draining || $this->stopping)) {
            return;
        }
        $this->sending = $this->outbox->take($now);
        $this->sender->start($this->sending->body());
    }

    private function pollSender(): void
    {
        if ($this->sending === null || ($response = $this->sender->poll()) === null) {
            return;
        }

        $batch = $this->sending;
        $this->sending = null;
        $now = ($this->clock)();
        $outcome = $this->outbox->settle($response->status, $response->retryAfter, $now);
        $this->stats['requests']++;

        $what = $response->status === 0 ? ($response->error ?? 'request failed') : "HTTP {$response->status}".($response->error ? ": {$response->error}" : '');

        switch ($outcome) {
            case Outcome::Sent:
                $this->stats['sent'] += count($batch->parts);
                $this->stats['bytes'] += $batch->bytes();
                if ($this->wasFailing) {
                    $this->log('info', 'lampwick reachable again, sending buffered records');
                    $this->wasFailing = false;
                }
                break;
            case Outcome::Retry:
                $this->stats['failures']++;
                $this->wasFailing = true;
                $message = sprintf(
                    '%s from %s, retrying in %.1fs (%s buffered)',
                    $what,
                    $this->sender->endpoint(),
                    max(0, $this->outbox->backoffUntil() - $now),
                    self::kb($this->outbox->bytes()),
                );
                $this->warn('retry', fn (int $n) => $n > 1 ? "{$message}; {$n} failed attempts since the last report" : $message);
                break;
            case Outcome::Split:
                $this->log('info', 'batch too large for lampwick (HTTP 413), sending it in smaller batches');
                break;
            case Outcome::Dropped:
                $hint = match ($response->status) {
                    401 => ' — check LAMPWICK_TOKEN (copy it from the project settings in lampwick)',
                    404 => ' — check LAMPWICK_URL',
                    default => '',
                };
                $this->log('error', sprintf('dropped %s of records: %s%s', self::kb($batch->bytes()), $what, $hint));
                break;
        }
    }

    private function summary(bool $force = false): void
    {
        $now = ($this->clock)();
        if (! $force && $now - $this->lastSummary < self::SUMMARY_INTERVAL) {
            return;
        }
        $this->lastSummary = $now;
        if ($this->stats['frames'] === 0 && $this->stats['requests'] === 0) {
            return;
        }
        $this->log('info', sprintf(
            'received %d payload(s), sent %d in %d request(s) (%s), %d failed request(s)',
            $this->stats['frames'],
            $this->stats['sent'],
            $this->stats['requests'],
            self::kb($this->stats['bytes']),
            $this->stats['failures'],
        ));
        $this->stats = ['frames' => 0, 'sent' => 0, 'requests' => 0, 'bytes' => 0, 'failures' => 0];
    }

    /**
     * Logs a warning at most once a minute per kind, with the number of occurrences.
     *
     * @param  Closure(int $count): string  $message
     */
    private function warn(string $kind, Closure $message, int $count = 1): void
    {
        $now = ($this->clock)();
        $state = $this->warnings[$kind] ?? ['count' => 0, 'last' => -INF];
        $state['count'] += $count;
        if ($now - $state['last'] >= 60.0) {
            $this->log('warning', $message($state['count']));
            $state = ['count' => 0, 'last' => $now];
        }
        $this->warnings[$kind] = $state;
    }

    private function log(string $level, string $message): void
    {
        ($this->log)($level, $message);
    }

    public static function isJsonArray(string $body): bool
    {
        $body = trim($body);
        if ($body === '' || $body[0] !== '[' || $body[-1] !== ']') {
            return false;
        }

        return function_exists('json_validate') ? json_validate($body) : json_decode($body) !== null;
    }

    private static function kb(int $bytes): string
    {
        return $bytes >= 1024 * 1024 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%.1f KB', $bytes / 1024);
    }
}
