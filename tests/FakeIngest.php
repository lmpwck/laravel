<?php

namespace Lampwick\Laravel\Tests;

use Symfony\Component\Process\Process;

/** Runs tests/Fixtures/fake-ingest.php under `php -S` on a free port. */
final class FakeIngest
{
    public const TOKEN = 'uk_test_0123456789abcdef';

    public readonly string $dir;

    public readonly string $url;

    private Process $process;

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/lampwick-fake-ingest-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $port = self::freePort();
        $this->url = "http://127.0.0.1:{$port}";
        $this->process = new Process(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/Fixtures/fake-ingest.php'],
            env: ['FAKE_INGEST_DIR' => $this->dir, 'FAKE_INGEST_TOKEN' => self::TOKEN, 'PHP_CLI_SERVER_WORKERS' => '1'],
        );
        $this->process->start();

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if ($socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1)) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }
        throw new \RuntimeException('fake ingest did not start: '.$this->process->getErrorOutput());
    }

    /** @param list<array{0: int, 1: int|null}> $responses */
    public function respondWith(array $responses): void
    {
        file_put_contents("{$this->dir}/responses.json", json_encode($responses));
    }

    /** @return list<array{method: string, path: string, authorization: ?string, content_length: ?string, content_type: ?string, body: string}> */
    public function requests(): array
    {
        $file = "{$this->dir}/requests.jsonl";
        if (! is_file($file)) {
            return [];
        }

        return array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", file_get_contents($file))));
    }

    /** @return list<array<string, mixed>> every record sent, including ones in rejected requests */
    public function records(): array
    {
        return array_merge(...array_map(fn ($r) => json_decode($r['body'], true), $this->requests()) ?: [[]]);
    }

    public function stop(): void
    {
        $this->process->stop(0);
        array_map('unlink', glob("{$this->dir}/*") ?: []);
        @rmdir($this->dir);
    }

    public static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /** Builds a frame the way the instrumentation does. */
    public static function frame(string $body, string $token = self::TOKEN): string
    {
        $payload = 'v1:'.substr(hash('xxh128', $token), 0, 7).':'.$body;

        return strlen($payload).':'.$payload;
    }
}
