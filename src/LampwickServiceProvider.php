<?php

namespace Lampwick\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Lampwick\Agent\Redactor;
use Lampwick\Laravel\Console\AgentCommand;
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
        return \Lampwick\Agent\Settings::version('lampwick/laravel');
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

            $this->commands([AgentCommand::class, TestCommand::class]);
        }

        self::redactRecords();
        self::useAgentStream($this->app->make(Repository::class));

        if ($this->registeredTooLate && $this->app->runningInConsole()) {
            $this->app->make('log')->warning('lampwick: LampwickServiceProvider was registered after the instrumentation provider, so LAMPWICK_* settings may not apply. Register Lampwick\Laravel\LampwickServiceProvider before Laravel\Nightwatch\NightwatchServiceProvider.');
        }
    }

    /**
     * Applies lampwick's redaction rules (Lampwick\Agent\Redactor) on top of the
     * instrumentation's header/payload redaction: sensitive query parameters and URL passwords
     * in request and outgoing request URLs, sensitive headers and payload fields by name, and
     * sensitive command options.
     */
    public static function redactRecords(): void
    {
        try {
            $core = app(\Laravel\Nightwatch\Core::class);
        } catch (\Throwable) {
            return;
        }

        if (method_exists($core, 'redactRequests')) {
            $core->redactRequests(static function ($record) use ($core): void {
                $record->url = Redactor::url(self::withoutSensitiveRouteParameters($record->url));
                // "GET /reset/<token>": the label of the request and of everything inside it.
                if (property_exists($core->executionState, 'executionPreview') && str_contains($preview = $core->executionState->executionPreview, ' ')) {
                    [$method, $path] = explode(' ', $preview, 2);
                    $core->executionState->executionPreview = mb_strcut($method.' '.Redactor::url(self::withoutSensitiveRouteParameters($path)), 0, 255);
                }
                foreach (array_keys($record->headers->all()) as $name) {
                    if (Redactor::isSensitive((string) $name)) {
                        $record->headers->set((string) $name, Redactor::REDACTED);
                    }
                }
                $record->payload->replace(Redactor::array($record->payload->all()));
            });
        }
        if (method_exists($core, 'redactOutgoingRequests')) {
            $core->redactOutgoingRequests(static function ($record): void {
                $record->url = Redactor::url($record->url, outgoing: true);
            });
        }
        if (method_exists($core, 'redactCommands')) {
            $core->redactCommands(static function ($record): void {
                $record->command = Redactor::commandLine($record->command);
            });
        }
        if (method_exists($core, 'redactExceptions')) {
            $core->redactExceptions(static function ($record) use ($core): void {
                $record->message = Redactor::text($record->message);
                // The execution's preview is the raw message; the instrumentation set it already.
                if (property_exists($core->executionState, 'exceptionPreview')) {
                    $core->executionState->exceptionPreview = Redactor::text($core->executionState->exceptionPreview);
                }
            });
        }
    }

    /**
     * Replaces the instrumentation's blocking socket with Transport\AgentStream, so a stuck agent
     * costs at most the connection timeout plus the timeout (1 s by default), never a hang.
     */
    public static function useAgentStream(Repository $config): void
    {
        try {
            $core = app(\Laravel\Nightwatch\Core::class);
        } catch (\Throwable) {
            return;
        }
        if (! $core->ingest instanceof \Laravel\Nightwatch\Ingest) {
            return; // a test fake or a custom ingest
        }
        $timeout = (float) $config->get('nightwatch.ingest.connection_timeout', 0.5) + (float) $config->get('nightwatch.ingest.timeout', 0.5);
        $core->ingest->streamFactory = Transport\AgentStream::factory(
            max(0.05, $timeout),
            (int) $config->get('lampwick.agent.max_frame_bytes', 8 * 1024 * 1024),
        );
    }

    /**
     * @return list<string> what this package relies on in the instrumentation that is missing:
     *                      the redaction hooks and the replaceable ingest stream
     */
    public static function missingRedactionHooks(): array
    {
        $missing = array_values(array_filter(
            ['redactRequests', 'redactOutgoingRequests', 'redactCommands', 'redactExceptions'],
            static fn (string $hook) => ! method_exists(\Laravel\Nightwatch\Core::class, $hook),
        ));
        foreach ([[\Laravel\Nightwatch\Core::class, 'ingest'], [\Laravel\Nightwatch\Ingest::class, 'streamFactory']] as [$class, $property]) {
            if (! property_exists($class, $property) || ! (new \ReflectionProperty($class, $property))->isPublic()) {
                $missing[] = (new \ReflectionClass($class))->getShortName().'::$'.$property;
            }
        }

        return $missing;
    }

    /** `/reset/{token}` routes: the values of sensitive route parameters leave the URL. */
    private static function withoutSensitiveRouteParameters(string $url): string
    {
        try {
            $parameters = request()->route()?->parameters() ?? [];
        } catch (\Throwable) {
            return $url;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $redacted = $path;
        foreach ($parameters as $name => $value) {
            if (is_scalar($value) && (string) $value !== '' && Redactor::isSensitiveRouteParameter((string) $name)) {
                $redacted = implode('/', array_map(static fn (string $segment) => rawurldecode($segment) === (string) $value ? Redactor::REDACTED : $segment, explode('/', $redacted)));
            }
        }

        $at = $path === '' || $redacted === $path ? false : strpos($url, $path);

        return $at === false ? $url : substr_replace($url, $redacted, $at, strlen($path));
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
        if ($enabled && ($missing = self::missingRedactionHooks()) !== []) {
            // Never send without the redaction: switch the instrumentation off instead.
            $enabled = false;
            error_log('lampwick: this laravel/nightwatch version lacks '.implode(', ', $missing).', so secrets could not be redacted or the app could block on the agent; monitoring is off. Update laravel/nightwatch.');
        }
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
                'tap' => [Logging\RedactLogContext::class],
                'level' => $config->get('nightwatch.filtering.log_level') ?? env('LOG_LEVEL', 'debug'),
            ]);
        }
    }
}
