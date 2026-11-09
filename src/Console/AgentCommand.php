<?php

namespace Lampwick\Laravel\Console;

use Illuminate\Console\Command;
use Lampwick\Agent\Runner;
use Lampwick\Agent\Settings;
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

        $settings = Settings::fromArray((array) config('lampwick', []))
            ->with(url: $this->option('url') ?: null, listen: $this->option('listen') ?: null);

        return Runner::run(
            $settings,
            Settings::userAgent('lampwick-laravel', LampwickServiceProvider::version()),
            $this->logger(...),
        );
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
        $line = Runner::line($level, $message);
        if ($level === 'error' || $level === 'warning') {
            $this->getOutput()->getErrorStyle()->writeln($line);
        } else {
            $this->getOutput()->writeln($line);
        }
    }
}
