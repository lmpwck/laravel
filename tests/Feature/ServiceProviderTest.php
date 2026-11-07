<?php

namespace Lampwick\Laravel\Tests\Feature;

use Lampwick\Laravel\Agent\Agent;
use Lampwick\Laravel\LampwickServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase;

final class ServiceProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $lampwick = [
        'token' => 'uk_provider_test',
        'agent' => ['listen' => '127.0.0.1:2499'],
        'sampling' => ['requests' => '0.25'],
        'filtering' => ['ignore_queries' => 'true'],
        'server' => 'web-1',
    ];

    protected function getPackageProviders($app): array
    {
        return [LampwickServiceProvider::class, NightwatchServiceProvider::class];
    }

    /** Config must be in place before the providers register, like config/lampwick.php in an app. */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('lampwick', $this->lampwick);
    }

    public function test_configures_the_instrumentation_from_lampwick_settings(): void
    {
        $config = $this->app['config'];
        $this->assertSame('uk_provider_test', $config->get('nightwatch.token'));
        $this->assertSame('127.0.0.1:2499', $config->get('nightwatch.ingest.uri'));
        $this->assertSame(0.25, $config->get('nightwatch.sampling.requests'));
        $this->assertTrue($config->get('nightwatch.filtering.ignore_queries'));
        $this->assertSame('web-1', $config->get('nightwatch.server'));
        $this->assertNotFalse($config->get('nightwatch.enabled'));
        $this->assertSame('custom', $config->get('logging.channels.lampwick.driver'));

        // The instrumentation was built with these values.
        $core = $this->app->make(Core::class);
        $this->assertTrue($core->enabled());
        $ingest = new \ReflectionObject($core->ingest);
        $this->assertSame('tcp://127.0.0.1:2499', $ingest->getProperty('transmitTo')->getValue($core->ingest));
        $this->assertSame(Agent::tokenHash('uk_provider_test'), $ingest->getProperty('tokenHash')->getValue($core->ingest));
    }

    public function test_disables_the_instrumentation_without_a_token(): void
    {
        $this->lampwick = ['token' => null];
        $this->refreshApplication();

        $this->assertFalse($this->app['config']->get('nightwatch.enabled'));
        $this->assertFalse($this->app->make(Core::class)->enabled());
    }

    public function test_can_be_switched_off(): void
    {
        $this->lampwick = ['token' => 'uk_x', 'enabled' => 'false'];
        $this->refreshApplication();

        $this->assertFalse($this->app->make(Core::class)->enabled());
    }

    public function test_registers_the_commands(): void
    {
        $this->artisan('list')->expectsOutputToContain('lampwick:agent')->assertSuccessful();
    }

    public function test_agent_refuses_plain_http_to_remote_hosts(): void
    {
        $this->artisan('lampwick:agent', ['--url' => 'http://lampwick.dev'])
            ->expectsOutputToContain('must use https://')
            ->assertFailed();
    }

    public function test_agent_requires_a_token(): void
    {
        $this->app['config']->set('lampwick.token', '');
        $this->artisan('lampwick:agent')->expectsOutputToContain('LAMPWICK_TOKEN is not set')->assertFailed();
    }
}
