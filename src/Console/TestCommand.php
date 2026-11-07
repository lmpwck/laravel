<?php

namespace Lampwick\Laravel\Console;

use Illuminate\Console\Command;
use Lampwick\Laravel\Agent\Agent;
use Lampwick\Laravel\Agent\HttpSender;
use Lampwick\Laravel\LampwickServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'lampwick:test', description: 'Check the lampwick configuration and send a test log record')]
final class TestCommand extends Command
{
    protected $signature = 'lampwick:test';

    protected $description = 'Check the lampwick configuration and send a test log record';

    public function handle(): int
    {
        AgentCommand::excludeFromMonitoring();

        $config = (array) config('lampwick', []);
        $token = (string) ($config['token'] ?? '');
        $url = (string) ($config['url'] ?? 'https://lampwick.dev');
        $listen = (string) ($config['agent']['listen'] ?? '127.0.0.1:2407');
        $ok = true;

        if ($token === '') {
            $this->components->error('LAMPWICK_TOKEN is not set.');

            return self::FAILURE;
        }
        if (($problem = AgentCommand::urlProblem($url)) !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        // 1. Token and URL: send one log record straight to lampwick.
        $sender = new HttpSender($url, $token, userAgent: 'lampwick-laravel/'.LampwickServiceProvider::VERSION, caBundle: $config['agent']['ca_bundle'] ?? null);
        $sender->start((string) json_encode([[
            't' => 'log',
            'timestamp' => microtime(true),
            'level' => 'info',
            'message' => 'lampwick:test from '.gethostname(),
            'context' => '{}',
            'extra' => '{}',
        ]]));
        $deadline = microtime(true) + 35;
        while (($response = $sender->poll()) === null && microtime(true) < $deadline) {
            usleep(10_000);
        }

        if ($response !== null && $response->status === 202) {
            $this->components->info("Sent a test log record to {$sender->endpoint()}; it shows up under Logs in lampwick.");
        } else {
            $ok = false;
            $reason = match (true) {
                $response === null => 'timed out',
                $response->status === 0 => (string) $response->error,
                $response->status === 401 => 'HTTP 401: the token is not valid; copy it from the project settings in lampwick',
                default => "HTTP {$response->status}".($response->error ? ": {$response->error}" : ''),
            };
            $this->components->error("Could not send to {$sender->endpoint()}: {$reason}");
        }

        // 2. The local agent: is it running and does it accept this app's frames?
        $stream = @stream_socket_client("tcp://{$listen}", $errno, $error, 1.0);
        if ($stream === false) {
            $ok = false;
            $this->components->warn("No agent is listening on {$listen}: run `php artisan lampwick:agent` (under Supervisor or as a Forge daemon in production).");
        } else {
            stream_set_timeout($stream, 2);
            $payload = 'v1:'.Agent::tokenHash($token).':PING';
            fwrite($stream, strlen($payload).':'.$payload);
            $answer = (string) fread($stream, 4);
            fclose($stream);
            if ($answer === '2:OK') {
                $this->components->info("The agent on {$listen} is running.");
            } else {
                $ok = false;
                $this->components->warn("Something on {$listen} did not answer like the lampwick agent; is it running with the same LAMPWICK_TOKEN?");
            }
        }

        if (! config('nightwatch.enabled', true)) {
            $this->components->warn('Monitoring is disabled for this app (LAMPWICK_ENABLED=false or no token).');
            $ok = false;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
