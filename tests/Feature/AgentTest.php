<?php

namespace Lampwick\Laravel\Tests\Feature;

use Lampwick\Laravel\Agent\Agent;
use Lampwick\Laravel\Agent\HttpSender;
use Lampwick\Laravel\Agent\Outbox;
use Lampwick\Laravel\Tests\FakeIngest;
use PHPUnit\Framework\TestCase;

/** Runs the agent in-process against a real local socket and the fake HTTP ingest. */
final class AgentTest extends TestCase
{
    private FakeIngest $ingest;

    private Agent $agent;

    private string $address;

    /** @var list<array{string, string}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->ingest = new FakeIngest;
        $this->agent = $this->makeAgent(FakeIngest::TOKEN, flushInterval: 0.2);
    }

    protected function tearDown(): void
    {
        $this->agent->drain(0);
        $this->ingest->stop();
    }

    private function makeAgent(string $token, float $flushInterval, int $batchBytes = 1024 * 1024): Agent
    {
        $agent = new Agent(
            listen: '127.0.0.1:0',
            outbox: new Outbox(batchBytes: $batchBytes, bufferBytes: 8 * 1024 * 1024, flushInterval: $flushInterval),
            sender: new HttpSender($this->ingest->url, $token, timeout: 5),
            tokenHash: Agent::tokenHash(FakeIngest::TOKEN),
            maxFrame: 1024 * 1024,
            maxConnections: 16,
            log: function (string $level, string $message) {
                $this->logs[] = [$level, $message];
            },
        );
        $this->address = $agent->listen();

        return $agent;
    }

    /** Sends one frame like the app does and returns the agent's answer. */
    private function send(string $frame): string
    {
        $client = stream_socket_client("tcp://{$this->address}", $errno, $error, 1);
        stream_set_blocking($client, false);
        $written = 0;
        $answer = '';
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if ($written < strlen($frame)) {
                $written += (int) fwrite($client, substr($frame, $written, 8192));
            }
            $this->agent->tick(0.01);
            $answer .= (string) fread($client, 4);
            if (strlen($answer) >= 4 || feof($client)) {
                break;
            }
        }
        fclose($client);

        return $answer;
    }

    private function tickUntil(callable $condition, float $timeout = 5.0): void
    {
        $deadline = microtime(true) + $timeout;
        while (! $condition() && microtime(true) < $deadline) {
            $this->agent->tick(0.01);
        }
        $this->assertTrue((bool) $condition(), 'condition not met in time');
    }

    public function test_acknowledges_frames_and_forwards_them_in_one_batch_with_the_full_token(): void
    {
        $this->assertSame('2:OK', $this->send(FakeIngest::frame('[{"t":"request","n":1}]')));
        $this->assertSame('2:OK', $this->send(FakeIngest::frame('[{"t":"query","n":2},{"t":"log","n":3}]')));
        $this->assertSame('2:OK', $this->send(FakeIngest::frame('PING')));
        $this->assertSame([], $this->ingest->requests(), 'records wait for the flush interval');

        $this->tickUntil(fn () => count($this->ingest->requests()) === 1);
        $request = $this->ingest->requests()[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('/ingest', $request['path']);
        $this->assertSame('Bearer '.FakeIngest::TOKEN, $request['authorization']);
        $this->assertSame('application/json', $request['content_type']);
        $this->assertSame((string) strlen($request['body']), $request['content_length']);
        $this->assertSame([['t' => 'request', 'n' => 1], ['t' => 'query', 'n' => 2], ['t' => 'log', 'n' => 3]], json_decode($request['body'], true));
    }

    public function test_handles_large_frames_written_in_many_pieces(): void
    {
        $records = array_map(fn ($i) => ['t' => 'log', 'message' => str_repeat('x', 1000), 'i' => $i], range(1, 300));
        $this->assertSame('2:OK', $this->send(FakeIngest::frame(json_encode($records))));
        $this->tickUntil(fn () => count($this->ingest->records()) === 300);
    }

    public function test_retries_after_503_honouring_retry_after(): void
    {
        $this->ingest->respondWith([[503, 1], [502, null]]);
        $this->send(FakeIngest::frame('[{"t":"log","n":1}]'));

        $this->tickUntil(fn () => count($this->ingest->requests()) === 1);
        $start = microtime(true);
        $this->tickUntil(fn () => count($this->ingest->requests()) === 2, 3);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $start, 'waited for Retry-After');
        $this->tickUntil(fn () => count($this->ingest->requests()) === 3, 4);

        $bodies = array_column($this->ingest->requests(), 'body');
        $this->assertSame([$bodies[0], $bodies[0]], [$bodies[1], $bodies[2]]);
        $this->assertSame('warning', $this->logs[0][0]);
        $this->assertStringContainsString('HTTP 503', $this->logs[0][1]);
    }

    public function test_keeps_buffering_while_lampwick_is_unreachable(): void
    {
        $this->ingest->stop();
        $this->send(FakeIngest::frame('[{"t":"log","n":1}]'));
        $this->tickUntil(fn () => $this->logs !== []);
        $this->assertStringContainsString('retrying in', $this->logs[0][1]);
        $this->assertSame('2:OK', $this->send(FakeIngest::frame('[{"t":"log","n":2}]')), 'still accepts frames');
        $this->ingest = new FakeIngest; // lets tearDown stop something
    }

    public function test_rejects_frames_for_another_token_without_acknowledging(): void
    {
        $this->assertSame('', $this->send(FakeIngest::frame('[{"t":"log"}]', token: 'some-other-token')));
        $this->assertStringContainsString('does not match LAMPWICK_TOKEN', $this->logs[0][1]);
    }

    public function test_drops_invalid_json_after_acknowledging(): void
    {
        $this->assertSame('2:OK', $this->send(FakeIngest::frame('[{"t":')));
        $this->assertStringContainsString('not a JSON array', $this->logs[0][1]);
    }

    public function test_logs_and_drops_on_401(): void
    {
        $this->agent->drain(0);
        $this->agent = $this->makeAgent('wrong-token', flushInterval: 0.0);
        $this->send(FakeIngest::frame('[{"t":"log"}]'));
        $this->tickUntil(fn () => $this->logs !== []);
        $this->assertSame('error', $this->logs[0][0]);
        $this->assertStringContainsString('HTTP 401', $this->logs[0][1]);
        $this->assertStringContainsString('LAMPWICK_TOKEN', $this->logs[0][1]);
    }

    public function test_drain_sends_everything_before_the_flush_interval(): void
    {
        $this->agent->drain(0);
        $this->agent = $this->makeAgent(FakeIngest::TOKEN, flushInterval: 3600, batchBytes: 40);
        foreach (range(1, 5) as $n) {
            $this->send(FakeIngest::frame("[{\"t\":\"log\",\"n\":{$n},\"pad\":\"xxxxxxxx\"}]"));
        }

        $this->assertTrue($this->agent->drain(5));
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->ingest->records(), 'n'));
        $this->assertGreaterThan(1, count($this->ingest->requests()), 'respects batch_bytes');
        $this->assertFalse(@stream_socket_client("tcp://{$this->address}", $errno, $error, 0.2), 'stopped listening');
    }
}
