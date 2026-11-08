<?php

namespace Lampwick\Laravel\Tests\Feature;

use Lampwick\Agent\Testing\FakeIngest;
use Lampwick\Laravel\LampwickServiceProvider;
use Lampwick\Laravel\Transport\AgentStream;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Laravel\Nightwatch\SocketStreamFactory;
use Orchestra\Testbench\TestCase;

/**
 * The app must never wait on the agent longer than the connection timeout plus the timeout
 * (1 s by default): the instrumentation's own socket writes without a deadline, so a listener
 * that accepts but never reads would hang the request, job or command.
 */
final class TransportTest extends TestCase
{
    private string $listen = '127.0.0.1:2499';

    /** @var resource|null */
    private $server = null;

    /** @var resource|null */
    private $child = null;

    protected function getPackageProviders($app): array
    {
        return [LampwickServiceProvider::class, NightwatchServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('lampwick', ['token' => 'uk_transport_test', 'agent' => ['listen' => $this->listen]]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if ($this->server) {
            fclose($this->server);
        }
        if ($this->child) {
            proc_terminate($this->child);
            proc_close($this->child);
        }
    }

    public function test_the_instrumentation_sends_through_lampwicks_stream(): void
    {
        $ingest = $this->app->make(Core::class)->ingest;
        $this->assertNotInstanceOf(SocketStreamFactory::class, $ingest->streamFactory);
        $stream = ($ingest->streamFactory)('tcp://127.0.0.1:1', 0.5);
        $this->assertInstanceOf(AgentStream::class, stream_get_meta_data($stream)['wrapper_data'] ?? null);
        fclose($stream);
    }

    public function test_a_listener_that_never_accepts_costs_at_most_the_timeout(): void
    {
        $this->server = stream_socket_server('tcp://127.0.0.1:0') ?: null;
        $this->listen = (string) stream_socket_get_name($this->server, false);
        $this->refreshApplication();

        $this->assertDigestGivesUpQuickly();
    }

    public function test_a_listener_that_accepts_but_never_reads_costs_at_most_the_timeout(): void
    {
        $port = FakeIngest::freePort();
        $script = '$s = stream_socket_server("tcp://127.0.0.1:'.$port.'"); fwrite(STDOUT, "ready\n"); $c = stream_socket_accept($s, 10); sleep(10);';
        $this->child = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes) ?: null;
        fgets($pipes[1]);
        $this->listen = "127.0.0.1:{$port}";
        $this->refreshApplication();

        $this->assertDigestGivesUpQuickly();
    }

    public function test_no_agent_costs_at_most_the_timeout(): void
    {
        $this->listen = '127.0.0.1:'.FakeIngest::freePort();
        $this->refreshApplication();

        $this->assertDigestGivesUpQuickly();
    }

    private function assertDigestGivesUpQuickly(): void
    {
        $ingest = $this->app->make(Core::class)->ingest;
        // About 6 MB: more than any socket buffer, so a blocking write would wait for the reader.
        foreach (range(1, 100) as $i) {
            $ingest->buffer->write(['t' => 'log', 'message' => str_repeat('x', 60_000), 'i' => $i]);
        }

        $start = microtime(true);
        try {
            $ingest->digest(); // the instrumentation drops the records
        } catch (\RuntimeException $e) {
            // Older versions throw "Unexpected response from agent" to their caller (Core catches it); newer ones catch it in digest().
            $this->assertStringContainsString('agent', $e->getMessage());
        }
        $this->assertLessThan(1.5, microtime(true) - $start);
        $this->assertNotNull(AgentStream::$lastError);
    }
}
