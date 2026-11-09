<?php

namespace Lampwick\Laravel\Tests\Feature;

use Lampwick\Laravel\LampwickServiceProvider;
use Lampwick\Agent\Testing\FakeIngest;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

/**
 * The whole path: the instrumentation in a Laravel app → `php artisan lampwick:agent` (a real
 * subprocess) → HTTP ingest (the fake), with SIGTERM flushing what is buffered.
 */
final class EndToEndTest extends TestCase
{
    private static FakeIngest $ingest;

    private static int $port;

    private Process $agent;

    public static function setUpBeforeClass(): void
    {
        self::$ingest = new FakeIngest;
        self::$port = FakeIngest::freePort();
    }

    public static function tearDownAfterClass(): void
    {
        self::$ingest->stop();
    }

    protected function getPackageProviders($app): array
    {
        return [LampwickServiceProvider::class, NightwatchServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('lampwick', [
            'token' => FakeIngest::TOKEN,
            'url' => self::$ingest->url,
            'agent' => ['listen' => '127.0.0.1:'.self::$port],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new Process([PHP_BINARY, 'vendor/bin/testbench', 'lampwick:agent'], dirname(__DIR__, 2), [
            'LAMPWICK_TOKEN' => FakeIngest::TOKEN,
            'LAMPWICK_URL' => self::$ingest->url,
            'LAMPWICK_AGENT_LISTEN' => '127.0.0.1:'.self::$port,
            'LAMPWICK_AGENT_FLUSH_INTERVAL' => '60',
        ]);
        $this->agent->start();
        $this->agent->waitUntil(fn ($type, $out) => str_contains($out, 'listening on'));
    }

    protected function tearDown(): void
    {
        if ($this->agent->isRunning()) {
            $this->agent->stop(1);
        }
        parent::tearDown();
    }

    public function test_records_from_the_instrumentation_reach_the_http_ingest(): void
    {
        $this->artisan('lampwick:test')->expectsOutputToContain('The agent on 127.0.0.1:'.self::$port.' is running')->assertSuccessful();

        Nightwatch::report(new \RuntimeException('boom from the end-to-end test'));
        $this->app->make(Core::class)->digest();

        // Flush interval is 60s, so the records are only sent because SIGTERM drains the buffer.
        $this->agent->signal(SIGTERM);
        $this->agent->wait();
        $this->assertSame(0, $this->agent->getExitCode(), $this->agent->getErrorOutput());
        $this->assertStringContainsString('stopped', $this->agent->getOutput());

        $records = self::$ingest->records();
        $types = array_column($records, 't');
        $this->assertContains('log', $types, 'the lampwick:test record, sent directly');
        $this->assertContains('exception', $types);
        $exception = $records[array_search('exception', $types, true)];
        $this->assertSame('boom from the end-to-end test', $exception['message']);
        foreach (self::$ingest->requests() as $request) {
            $this->assertSame('Bearer '.FakeIngest::TOKEN, $request['authorization']);
        }
    }
}
