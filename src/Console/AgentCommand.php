<?php

namespace Lampwick\Laravel\Console;

use Illuminate\Console\Command;
use Lampwick\Laravel\Agent\Agent;
use Lampwick\Laravel\Agent\HttpSender;
use Lampwick\Laravel\Agent\Outbox;
use Lampwick\Laravel\LampwickServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'lampwick:agent', description: 'Run the local agent that forwards monitoring data to lampwick over HTTPS')]
final class AgentCommand extends Command
{
    protected $signature = 'lampwick:agent
        {--listen= : Local address to accept records on (default: LAMPWICK_AGENT_LISTEN or 127.0.0.1:2407)}
        {--url= : lampwick address (default: LAMPWICK_URL or https://lampwick.dev)}';

    protected $description = 'Run the local agent that forwards monitoring data to lampwick over HTTPS';

    public function handle(): int
    {
        self::excludeFromMonitoring();

        $config = (array) config('lampwick', []);
        $agentConfig = (array) ($config['agent'] ?? []);
        $token = (string) ($config['token'] ?? '');
        $url = (string) ($this->option('url') ?: ($config['url'] ?? 'https://lampwick.dev'));
        $listen = (string) ($this->option('listen') ?: ($agentConfig['listen'] ?? '127.0.0.1:2407'));

        if ($token === '') {
            $this->components->error('LAMPWICK_TOKEN is not set. Copy the token from your project\'s settings page in lampwick.');

            return self::FAILURE;
        }
        if (($problem = self::urlProblem($url)) !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        $sender = new HttpSender(
            $url,
            $token,
            connectTimeout: (float) ($agentConfig['connect_timeout'] ?? 5.0),
            timeout: (float) ($agentConfig['timeout'] ?? 30.0),
            userAgent: 'lampwick-laravel/'.LampwickServiceProvider::VERSION.' PHP/'.PHP_VERSION,
            caBundle: $agentConfig['ca_bundle'] ?? null,
        );
        $agent = new Agent(
            listen: $listen,
            outbox: new Outbox(
                batchBytes: max(1024, (int) ($agentConfig['batch_bytes'] ?? 2 * 1024 * 1024)),
                bufferBytes: max(1024 * 1024, (int) ($agentConfig['buffer_bytes'] ?? 64 * 1024 * 1024)),
                flushInterval: max(0.0, (float) ($agentConfig['flush_interval'] ?? 1.0)),
            ),
            sender: $sender,
            tokenHash: Agent::tokenHash($token),
            maxFrame: (int) ($agentConfig['max_frame_bytes'] ?? 16 * 1024 * 1024),
            maxConnections: max(1, (int) ($agentConfig['max_connections'] ?? 256)),
            log: $this->logger(...),
        );

        try {
            $address = $agent->listen();
        } catch (\RuntimeException $e) {
            $this->components->error($e->getMessage().'. Is another agent already running?');

            return self::FAILURE;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
                pcntl_signal($signal, static fn () => $agent->stop());
            }
        }

        $this->logger('info', "listening on {$address}, forwarding to {$sender->endpoint()}");
        $agent->run((float) ($agentConfig['shutdown_timeout'] ?? 10.0));
        $this->logger('info', 'stopped');

        return self::SUCCESS;
    }

    /** Returns why `$url` is not acceptable, or null. Plain HTTP is only allowed to this machine. */
    public static function urlProblem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return "LAMPWICK_URL \"{$url}\" is not a valid http(s) URL.";
        }
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');
        if ($scheme === 'http' && ! $local) {
            return "LAMPWICK_URL must use https:// (plain http is only allowed for localhost): {$url}";
        }

        return null;
    }

    /** Keeps the agent's own (endless) command out of the monitoring data. */
    public static function excludeFromMonitoring(): void
    {
        try {
            if (class_exists(\Laravel\Nightwatch\Facades\Nightwatch::class)) {
                \Laravel\Nightwatch\Facades\Nightwatch::dontSample();
            }
        } catch (\Throwable) {
            // instrumentation disabled or not bound
        }
    }

    private function logger(string $level, string $message): void
    {
        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $level, $message);
        if ($level === 'error' || $level === 'warning') {
            $this->getOutput()->getErrorStyle()->writeln($line);
        } else {
            $this->getOutput()->writeln($line);
        }
    }
}
