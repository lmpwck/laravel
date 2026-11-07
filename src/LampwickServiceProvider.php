<?php

namespace Lampwick\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Lampwick\Laravel\Console\AgentCommand;
use Lampwick\Laravel\Console\DeployCommand;
use Lampwick\Laravel\Console\TestCommand;
use Laravel\Nightwatch\NightwatchServiceProvider;

/**
 * Configures the instrumentation from `config/lampwick.php` and registers the agent commands.
 *
 * The instrumentation (laravel/nightwatch) reads its configuration while it registers, so this
 * provider must register first. Package discovery registers providers in package-name order,
 * which puts lampwick/laravel before laravel/nightwatch.
 */
final class LampwickServiceProvider extends ServiceProvider
{
    /** The installed package version (from Composer), e.g. "v1.0.0", for the User-Agent. */
    public static function version(): string
    {
        try {
            return \Composer\InstalledVersions::getPrettyVersion('lampwick/laravel') ?? 'dev';
        } catch (\Throwable) {
            return 'dev';
        }
    }

    private bool $registeredTooLate = false;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lampwick.php', 'lampwick');

        $this->registeredTooLate = $this->app->getProviders(NightwatchServiceProvider::class) !== [];

        self::configureInstrumentation($this->app->make(Repository::class));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/lampwick.php' => $this->app->configPath('lampwick.php')], 'lampwick-config');

            $this->commands([AgentCommand::class, DeployCommand::class, TestCommand::class]);
        }

        if ($this->registeredTooLate && $this->app->runningInConsole()) {
            $this->app->make('log')->warning('lampwick: LampwickServiceProvider was registered after the instrumentation provider, so LAMPWICK_* settings may not apply. Register Lampwick\Laravel\LampwickServiceProvider before Laravel\Nightwatch\NightwatchServiceProvider.');
        }
    }

    /**
     * Copies the lampwick settings onto the instrumentation's configuration. Values left null in
     * `config/lampwick.php` keep the instrumentation's own default.
     */
    public static function configureInstrumentation(Repository $config): void
    {
        $lampwick = (array) $config->get('lampwick', []);
        $token = (string) ($lampwick['token'] ?? '');

        if ($token !== '') {
            $config->set('nightwatch.token', $token);
        }

        $enabled = filter_var($lampwick['enabled'] ?? true, FILTER_VALIDATE_BOOL);
        if (! $enabled || ($token === '' && ! $config->get('nightwatch.token'))) {
            // Without a token there is nobody to send to: don't instrument at all.
            $config->set('nightwatch.enabled', false);
        }

        $config->set('nightwatch.ingest.uri', $lampwick['agent']['listen'] ?? '127.0.0.1:2407');

        $map = [
            'server' => 'server',
            'deployment' => 'deployment',
            'capture_exception_source_code' => 'capture_exception_source_code',
            'capture_request_payload' => 'capture_request_payload',
            'redact_payload_fields' => 'redact_payload_fields',
            'redact_headers' => 'redact_headers',
        ];
        foreach (['requests', 'commands', 'exceptions', 'scheduled_tasks'] as $key) {
            $map["sampling.$key"] = "sampling.$key";
        }
        foreach (['ignore_cache_events', 'ignore_mail', 'ignore_notifications', 'ignore_outgoing_requests', 'ignore_queries', 'log_level'] as $key) {
            $map["filtering.$key"] = "filtering.$key";
        }

        foreach ($map as $from => $to) {
            $value = data_get($lampwick, $from);
            if ($value === null || $value === '') {
                continue;
            }
            $value = match (true) {
                str_starts_with($from, 'sampling.') => (float) $value,
                str_starts_with($from, 'filtering.ignore_'), str_starts_with($from, 'capture_') => filter_var($value, FILTER_VALIDATE_BOOL),
                default => $value,
            };
            $config->set("nightwatch.$to", $value);
        }

        // `LOG_STACK=single,lampwick` sends application logs to lampwick.
        if (! $config->has('logging.channels.lampwick')) {
            $config->set('logging.channels.lampwick', [
                'driver' => 'custom',
                'via' => \Laravel\Nightwatch\Factories\Logger::class,
                'level' => $config->get('nightwatch.filtering.log_level') ?? env('LOG_LEVEL', 'debug'),
            ]);
        }
    }
}
