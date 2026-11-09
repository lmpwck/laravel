<?php

namespace Lampwick\Laravel\Tests\Feature;

use Lampwick\Laravel\Console\DeployCommand;
use Lampwick\Laravel\LampwickServiceProvider;
use Lampwick\Agent\Testing\FakeIngest;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase;

final class DeployCommandTest extends TestCase
{
    private static FakeIngest $ingest;

    public static function setUpBeforeClass(): void
    {
        self::$ingest = new FakeIngest;
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
        $app['config']->set('lampwick', ['token' => FakeIngest::TOKEN, 'url' => self::$ingest->url, 'agent' => ['listen' => '127.0.0.1:2498']]);
    }

    public function test_sends_the_deploy_with_the_project_token(): void
    {
        $this->artisan('lampwick:deploy', [
            'release' => 'v1.4.0',
            '--commit' => '9e1c7d4b2a0f',
            '--repo' => 'git@github.com:acme/shop.git',
            '--author' => 'sanne',
            '--environment' => 'production',
        ])->assertSuccessful();

        $request = array_values(array_filter(self::$ingest->requests(), fn ($r) => $r['path'] === '/api/v1/deploys'))[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('Bearer '.FakeIngest::TOKEN, $request['authorization']);
        $this->assertSame([
            'version' => 'v1.4.0',
            'commit_sha' => '9e1c7d4b2a0f',
            'repo_url' => 'https://github.com/acme/shop',
            'author' => 'sanne',
            'environment' => 'production',
        ], json_decode($request['body'], true));
    }

    public function test_fails_on_a_rejected_token(): void
    {
        self::$ingest->respondWith([[401, null]]);
        $this->artisan('lampwick:deploy', ['release' => 'v1'])->assertFailed();
    }

    public function test_repo_urls(): void
    {
        $this->assertSame('https://github.com/acme/shop', DeployCommand::repoUrl('git@github.com:acme/shop.git'));
        $this->assertSame('https://gitlab.com/acme/shop', DeployCommand::repoUrl('ssh://git@gitlab.com:22/acme/shop.git'));
        $this->assertSame('https://github.com/acme/shop', DeployCommand::repoUrl('https://x-access-token:abc@github.com/acme/shop.git'));
        $this->assertSame('https://github.com/acme/shop', DeployCommand::repoUrl('https://user:pa@ss@github.com/acme/shop.git?token=x#frag'));
        $this->assertSame('https://git.example.com:8443/acme/shop', DeployCommand::repoUrl('https://ci@git.example.com:8443/acme/shop/'));
        $this->assertNull(DeployCommand::repoUrl('/srv/repos/shop.git'));
        $this->assertNull(DeployCommand::repoUrl('file:///srv/repos/shop.git'));
        $this->assertNull(DeployCommand::repoUrl(null));
    }
}
