<?php

namespace Lampwick\Laravel\Tests\Contract;

use Lampwick\Agent\Testing\Contract;
use Lampwick\Agent\Testing\FakeIngest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Contract between this package (and the laravel/nightwatch version it runs on) and lampwick's
 * ingest: a small Laravel app (tests/Contract/App) handles HTTP requests, runs a command, a
 * queue worker and the scheduler, all as real processes, and sends through a real
 * `lampwick:agent` to a fake HTTP ingest. Every record that arrives must have the fields and
 * types lampwick reads (Lampwick\Agent\Testing\Contract), the fingerprints it groups by, and
 * link to its execution. A Nightwatch release that changes the format fails here.
 */
final class ContractTest extends TestCase
{
    private static FakeIngest $ingest;

    /** @var list<array<string, mixed>> */
    private static array $records = [];

    private static string $agentOutput = '';

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        self::$ingest = new FakeIngest;
        $database = sys_get_temp_dir().'/lampwick-contract-'.bin2hex(random_bytes(4)).'.sqlite';
        self::createQueueTable($database);

        $listen = '127.0.0.1:'.FakeIngest::freePort();
        $env = [
            'CONTRACT_APP' => '1',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'APP_ENV' => 'production',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'lampwick',
            'LAMPWICK_TOKEN' => FakeIngest::TOKEN,
            'LAMPWICK_URL' => self::$ingest->url,
            'LAMPWICK_AGENT_LISTEN' => $listen,
            'LAMPWICK_AGENT_FLUSH_INTERVAL' => '60',
            'LAMPWICK_SERVER' => 'contract-1',
            'LAMPWICK_DEPLOY' => 'v-contract',
            'TESTBENCH_WORKING_PATH' => $root,
        ];
        $testbench = [PHP_BINARY, $root.'/vendor/bin/testbench'];

        $agent = new Process([...$testbench, 'lampwick:agent'], $root, $env);
        $agent->start();
        $agent->waitUntil(fn ($type, $out) => str_contains($out, 'listening on'));

        $port = FakeIngest::freePort();
        $web = new Process([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/App/server.php'], $root, $env + ['PHP_CLI_SERVER_WORKERS' => '1']);
        $web->start();
        self::waitForPort($port);

        try {
            self::get("http://127.0.0.1:{$port}/contract/7?api_key=SECRET-query&page=2", ['Authorization: Bearer SECRET-bearer', 'X-Custom-Token: SECRET-header', 'Cookie: session=SECRET-cookie']);
            self::get("http://127.0.0.1:{$port}/reset/SECRET-route-token");
            self::get("http://127.0.0.1:{$port}/email/42/SECRET-hash/SECRET-nonce");
            self::get("http://127.0.0.1:{$port}/contract-fail");
            self::get("http://127.0.0.1:{$port}/does-not-exist");
            foreach ([['contract:command'], ['contract:command', '--fail', '--password=SECRET-option'], ['queue:work', '--once', '--stop-when-empty'], ['schedule:run']] as $command) {
                $process = new Process([...$testbench, ...$command], $root, $env);
                $process->run();
            }
        } finally {
            $web->stop(0);
            // The flush interval is 60s: everything arrives because SIGTERM drains the buffer.
            $agent->signal(SIGTERM);
            $agent->wait();
            self::$agentOutput = $agent->getOutput().$agent->getErrorOutput();
            @unlink($database);
        }

        self::$records = self::$ingest->records();
    }

    public static function tearDownAfterClass(): void
    {
        self::$ingest->stop();
    }

    public function test_the_agent_forwards_everything_with_the_full_token(): void
    {
        $this->assertStringContainsString('stopped', self::$agentOutput);
        $this->assertStringNotContainsString('dropped', self::$agentOutput);
        $this->assertNotEmpty(self::$ingest->requests());
        foreach (self::$ingest->requests() as $request) {
            $this->assertSame('Bearer '.FakeIngest::TOKEN, $request['authorization']);
            $this->assertStringStartsWith('lampwick-laravel/', (string) $request['user_agent']);
        }
    }

    public function test_every_record_type_lampwick_shows_is_sent(): void
    {
        $types = array_unique(array_column(self::$records, 't'));
        foreach (array_keys(Contract::fields()) as $type) {
            $this->assertContains($type, $types, "no {$type} record was sent; agent output:\n".self::$agentOutput);
        }
    }

    public function test_every_record_has_the_fields_lampwick_reads(): void
    {
        $problems = [];
        foreach (self::$records as $record) {
            foreach (Contract::violations($record) as $problem) {
                $problems[$problem] = true;
            }
        }
        $this->assertSame([], array_keys($problems));
    }

    public function test_records_link_to_their_execution(): void
    {
        $this->assertSame([], Contract::linkViolations(self::$records));
    }

    public function test_no_secret_is_sent(): void
    {
        foreach (self::$ingest->requests() as $request) {
            $this->assertStringNotContainsString('SECRET', $request['body']);
        }
        $show = $this->one('request', fn ($r) => $r['route_name'] === 'contract.show');
        $this->assertStringEndsWith('/contract/7?api_key=[redacted]&page=2', $show['url']);
        $this->assertStringEndsWith('/ping?access_token=[redacted]', $this->one('outgoing-request', fn ($r) => $r['host'] === 'example.com')['url']);
        $this->assertSame('https://hooks.slack.com/services/[redacted]/[redacted]/[redacted]', $this->one('outgoing-request', fn ($r) => $r['host'] === 'hooks.slack.com')['url']);
        $this->assertStringEndsWith('/reset/[redacted]', $this->one('request', fn ($r) => $r['route_name'] === 'contract.reset')['url']);
        $this->assertStringEndsWith('/email/42/[redacted]/[redacted]', $this->one('request', fn ($r) => $r['route_name'] === 'contract.email')['url']);
        $this->one('log', fn ($r) => $r['message'] === 'calling https://api.example.com/v1?api_key=[redacted]');
        $this->one('exception', fn ($r) => $r['message'] === 'callback to https://deploy:[redacted]@ci.example.com/hook failed');
        $log = json_decode($this->one('log', fn ($r) => $r['message'] === 'contract log')['context'], true);
        $this->assertSame(['id' => '7', 'password' => '[redacted]', 'user' => ['api_token' => '[redacted]']], $log);
    }

    public function test_request_records(): void
    {
        $show = $this->one('request', fn ($r) => $r['route_name'] === 'contract.show');
        $this->assertSame('/contract/{id}', $show['route_path']);
        $this->assertSame(['GET', 'HEAD'], $show['route_methods']);
        $this->assertSame(200, $show['status_code']);
        $this->assertSame('42', $show['user']);
        $this->assertSame('contract-1', $show['server']);
        $this->assertSame('v-contract', $show['deploy']);
        $this->assertGreaterThan(0, $show['duration']);

        $children = array_filter(self::$records, fn ($r) => ($r['execution_id'] ?? null) === $show['trace_id']);
        $this->assertEqualsCanonicalizing(
            ['query', 'cache-event', 'outgoing-request', 'mail', 'notification', 'queued-job', 'log', 'exception'],
            array_values(array_unique(array_column($children, 't'))),
        );

        $this->assertSame(500, $this->one('request', fn ($r) => $r['route_path'] === '/contract-fail')['status_code']);
        $this->assertSame(404, $this->one('request', fn ($r) => $r['route_path'] === '')['status_code'], 'unmatched routes have an empty route_path');
        $this->assertSame('Ada Lovelace', $this->one('user', fn ($r) => $r['id'] === '42')['name']);
    }

    public function test_exception_records(): void
    {
        $handled = $this->one('exception', fn ($r) => $r['message'] === 'handled in the contract app');
        $this->assertTrue($handled['handled']);
        $this->assertSame('RuntimeException', $handled['class']);
        $this->assertSame('request', $handled['execution_source']);

        $unhandled = $this->one('exception', fn ($r) => $r['message'] === 'unhandled in the contract app');
        $this->assertFalse($unhandled['handled']);
        $frames = json_decode($unhandled['trace'], true);
        $this->assertSame($unhandled['file'].':'.$unhandled['line'], $frames[0]['file'], 'the first frame is the exception location');
    }

    public function test_job_records_carry_the_trace_of_the_request_that_queued_them(): void
    {
        $request = $this->one('request', fn ($r) => $r['route_name'] === 'contract.show');
        $queued = $this->one('queued-job', fn ($r) => true);
        $attempt = $this->one('job-attempt', fn ($r) => true);

        $this->assertSame(App\ContractJob::class, $attempt['name']);
        $this->assertSame($queued['name'], $attempt['name']);
        $this->assertSame($queued['job_id'], $attempt['job_id']);
        $this->assertSame('processed', $attempt['status']);
        $this->assertSame('database', $attempt['connection']);
        $this->assertSame($request['trace_id'], $attempt['trace_id'], 'the trace continues in the worker');
        $this->assertNotSame($attempt['trace_id'], $attempt['attempt_id']);
        $this->one('log', fn ($r) => $r['message'] === 'contract job ran' && $r['execution_id'] === $attempt['attempt_id'] && $r['execution_source'] === 'job');
    }

    public function test_command_and_scheduled_task_records(): void
    {
        $ok = $this->one('command', fn ($r) => $r['command'] === 'contract:command');
        $this->assertSame('contract:command', $ok['name']);
        $this->assertSame(0, $ok['exit_code']);
        $this->assertSame(1, $this->one('command', fn ($r) => $r['command'] === 'contract:command --fail --password=[redacted]')['exit_code']);

        $task = $this->one('scheduled-task', fn ($r) => $r['name'] === 'contract-task');
        $this->assertSame('* * * * *', $task['cron']);
        $this->assertSame('processed', $task['status']);
        $this->assertSame('UTC', $task['timezone']);
        $this->one('query', fn ($r) => $r['sql'] === 'select 3 as scheduled' && $r['execution_source'] === 'schedule' && $r['execution_id'] === $task['trace_id']);
    }

    public function test_child_records(): void
    {
        $query = $this->one('query', fn ($r) => $r['sql'] === 'select ? as id');
        $this->assertSame('sqlite', $query['connection']);
        $this->assertSame(200, $this->one('outgoing-request', fn ($r) => $r['host'] === 'example.com')['status_code']);
        $this->one('cache-event', fn ($r) => $r['type'] === 'miss' && $r['key'] === 'contract-key');
        $this->one('cache-event', fn ($r) => $r['type'] === 'write' && $r['ttl'] === 60);
        $mail = $this->one('mail', fn ($r) => true);
        $this->assertSame([App\ContractMail::class, 'Contract mail', 1], [$mail['class'], $mail['subject'], $mail['to']]);
        $this->assertSame('mail', $this->one('notification', fn ($r) => true)['channel']);
        $log = $this->one('log', fn ($r) => $r['message'] === 'contract log');
        $this->assertSame('warning', $log['level']);
        $this->assertSame('7', json_decode($log['context'], true)['id']);
    }

    /** @return array<string, mixed> the only record of type `$t` that matches */
    private function one(string $t, callable $match): array
    {
        $found = array_values(array_filter(self::$records, fn ($r) => ($r['t'] ?? null) === $t && $match($r)));
        $this->assertCount(1, $found, "expected one matching {$t} record");

        return $found[0];
    }

    /** @param list<string> $headers */
    private static function get(string $url, array $headers = []): void
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30, 'header' => $headers]]);
        file_get_contents($url, false, $context);
    }

    private static function waitForPort(int $port): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            if ($socket = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1)) {
                fclose($socket);

                return;
            }
            usleep(20_000);
        }
        throw new \RuntimeException("nothing listens on port {$port}");
    }

    /** The table of Laravel's database queue driver. */
    private static function createQueueTable(string $database): void
    {
        (new \PDO("sqlite:{$database}"))->exec('create table jobs (
            id integer primary key autoincrement, queue varchar not null, payload text not null,
            attempts integer not null, reserved_at integer, available_at integer not null, created_at integer not null)');
    }
}
