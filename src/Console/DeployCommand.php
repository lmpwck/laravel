<?php

namespace Lampwick\Laravel\Console;

use Illuminate\Console\Command;
use Lampwick\Agent\Settings;
use Lampwick\Laravel\LampwickServiceProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

/**
 * Records a deploy in lampwick (`POST {url}/api/v1/deploys` with the project token), so the
 * charts get a marker and new issues are attributed to it. Run it right after a deploy, e.g. as
 * the last step of a Forge or Envoyer deploy script.
 */
#[AsCommand(name: 'lampwick:deploy', description: 'Record a deploy in lampwick')]
final class DeployCommand extends Command
{
    protected $signature = 'lampwick:deploy
        {release? : What was deployed (default: LAMPWICK_DEPLOY, or the current git commit)}
        {--commit= : The deployed commit (default: the current git commit)}
        {--repo= : The repository URL, to link the commit (default: the git remote "origin")}
        {--author= : Who deployed}
        {--environment= : The environment (default: the app environment)}';

    protected $description = 'Record a deploy in lampwick';

    public function handle(): int
    {
        AgentCommand::excludeFromMonitoring();

        $config = (array) config('lampwick', []);
        $token = (string) ($config['token'] ?? '');
        $url = (string) ($config['url'] ?? 'https://app.lampwick.dev');

        if ($token === '') {
            $this->components->error('LAMPWICK_TOKEN is not set.');

            return self::FAILURE;
        }
        if (($problem = Settings::urlProblem($url)) !== null) {
            $this->components->error($problem);

            return self::FAILURE;
        }

        $commit = $this->option('commit') ?: $this->git('rev-parse', 'HEAD');
        $version = $this->argument('release') ?: ($config['deployment'] ?? null) ?: ($commit !== null ? substr($commit, 0, 12) : null);
        if ($version === null || $version === '') {
            $this->components->error('Pass the release (`php artisan lampwick:deploy v1.2.0`), set LAMPWICK_DEPLOY, or run this in a git checkout.');

            return self::FAILURE;
        }

        $deploy = array_filter([
            'version' => (string) $version,
            'commit_sha' => $commit !== null && preg_match('/^[0-9a-f]{4,64}$/i', $commit) ? $commit : null,
            'repo_url' => self::repoUrl($this->option('repo') ?: $this->git('config', '--get', 'remote.origin.url')),
            'author' => $this->option('author') ?: null,
            'environment' => $this->option('environment') ?: $this->laravel->environment(),
        ], fn ($v) => $v !== null && $v !== '');

        [$status, $body, $error] = $this->post(rtrim($url, '/').'/api/v1/deploys', $token, $deploy, $config['agent']['ca_bundle'] ?? null);

        if ($status === 201) {
            $this->components->info("Recorded deploy {$deploy['version']} in Lampwick.");

            return self::SUCCESS;
        }

        $reason = match (true) {
            $status === 0 => (string) $error,
            $status === 401 => 'HTTP 401: the token is not valid; copy it from the project settings in lampwick',
            default => "HTTP {$status}: ".(json_decode((string) $body, true)['error']['message'] ?? $body),
        };
        $this->components->error("Could not record the deploy: {$reason}");

        return self::FAILURE;
    }

    /**
     * An http(s) URL for a git remote: `git@github.com:acme/shop.git` becomes
     * `https://github.com/acme/shop`. Other remotes (local paths, ...) give null.
     */
    public static function repoUrl(?string $remote): ?string
    {
        $remote = trim((string) $remote);
        if (preg_match('#^[\w.-]+@([\w.-]+):(.+)$#', $remote, $m)) {
            $remote = "https://{$m[1]}/{$m[2]}";
        } elseif (preg_match('#^ssh://[\w.-]+@([\w.-]+)(?::\d+)?/(.+)$#', $remote, $m)) {
            $remote = "https://{$m[1]}/{$m[2]}";
        }
        // Rebuilt from its parts, so credentials (https://user:token@host/...), the query and the
        // fragment never leave the machine.
        $parts = parse_url($remote);
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($parts === false || ! in_array($scheme, ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme.'://'.$parts['host'].$port.(string) preg_replace('#\.git$#', '', $path);
    }

    private function git(string ...$args): ?string
    {
        try {
            $process = new Process(['git', ...$args], $this->laravel->basePath());
            $process->setTimeout(5);
            $process->run();
        } catch (\Throwable) {
            return null;
        }
        $out = trim($process->getOutput());

        return $process->isSuccessful() && $out !== '' ? $out : null;
    }

    /**
     * @param  array<string, string>  $deploy
     * @return array{0: int, 1: string|false, 2: string}
     */
    private function post(string $endpoint, #[\SensitiveParameter] string $token, array $deploy, ?string $caBundle): array
    {
        $body = (string) json_encode($deploy);
        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERAGENT => 'lampwick-laravel/'.LampwickServiceProvider::version(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($caBundle !== null && $caBundle !== '') {
            curl_setopt($handle, CURLOPT_CAINFO, $caBundle);
        }
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);

        return [$status, $response, $error];
    }
}
