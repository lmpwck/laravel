<?php

namespace Lampwick\Laravel\Console;

use Illuminate\Console\Command;
use Lampwick\Agent\Diagnostics;
use Lampwick\Agent\Settings;
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

        $settings = Settings::fromArray((array) config('lampwick', []));
        if ($settings->token === '') {
            $this->components->error('LAMPWICK_TOKEN is not set.');

            return self::FAILURE;
        }
        if (($problem = $settings->problem()) !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        // 1. Token and URL: send one log record straight to lampwick.
        [$sent, $message] = Diagnostics::sendTestRecord($settings, Settings::userAgent('lampwick-laravel', LampwickServiceProvider::version()));
        $sent ? $this->components->info($message) : $this->components->error($message);

        // 2. The local agent: is it running and does it accept this app's frames?
        [$running, $message] = Diagnostics::pingAgent($settings, 'php artisan lampwick:agent');
        $running ? $this->components->info($message) : $this->components->warn($message);

        $enabled = (bool) config('nightwatch.enabled', true);
        if (! $enabled) {
            $this->components->warn('Monitoring is disabled for this app (LAMPWICK_ENABLED=false or no token).');
        }

        return $sent && $running && $enabled ? self::SUCCESS : self::FAILURE;
    }
}
