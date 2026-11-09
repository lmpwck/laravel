# Lampwick for Laravel

Send requests, queries, jobs, exceptions, logs and more from your Laravel app to
[Lampwick](https://lampwick.dev), over HTTPS.

```
your app ──local TCP──▶ php artisan lampwick:agent ──HTTPS + project token──▶ https://lampwick.dev/ingest
```

Your app hands its records to a small agent on the same server (a quick write to
`127.0.0.1:2407`, so requests never wait on the network). The agent batches them and sends them
to Lampwick over HTTPS, authenticated with your full project token. If Lampwick is briefly
unreachable, the agent keeps the records in memory (bounded) and retries.

The instrumentation itself comes from the MIT-licensed
[`laravel/nightwatch`](https://github.com/laravel/nightwatch) package, which is installed as a
dependency (pinned to the versions this package is tested with); you configure everything with
the `LAMPWICK_*` variables below.

Requirements: PHP 8.2+ with the `curl` extension, Laravel 12 or 13. The `pcntl` extension
(present on almost every Linux PHP build) lets the agent flush its buffer when it is stopped.

## Install

```bash
composer require lampwick/laravel
echo "LAMPWICK_TOKEN=uk_..." >> .env
php artisan lampwick:agent
```

Use your project token from the project's **Settings** page in Lampwick. Open your app; within a
few seconds the request shows up in Lampwick. In production, keep the agent running under a
process manager (below).

`php artisan lampwick:test` checks the token, the network and the agent. To also send your
application logs, add the `lampwick` channel to your log stack: `LOG_STACK=single,lampwick`.

## What is never sent

Before anything leaves your app, these values are replaced by `[redacted]`:

- **Sensitive names**, wherever a name comes with a value: request headers, request payload
  fields, URL query and fragment parameters (nested ones too, `user[api_token]`), log context
  and extra, and command options. A name is sensitive when it contains `passw`, `passphrase`,
  `secret`, `token`, `apikey`, `accesskey`, `privatekey`, `auth` (not `author`), `credential`,
  `cookie`, `session`, `sessid`, `signature`, `csrf`, `xsrf`, `otp`, `cvv`, `cvc`,
  `cardnumber`, `iban`, `ssn`, `bearer`, `jwt`, `assertion`, `oidc`, `dsn`, `databaseurl`,
  `connectionstring` or a localized "password" (`wachtwoord`, `passwort`, `motdepasse`, ...);
  when it ends in `key` (`stripe_key`, `app_key`; not `primary_key`, `cache_key`,
  `idempotency_key`, `sort_key`); or when it is `pin`, `sig`, `code`, `key`, `pw`, `pwd`,
  `pass`. Case, `-`, `_` and `.` are ignored.
- **Secrets in URLs**: user info (`https://user:secret@host` → `https://user:[redacted]@host`,
  a token as user name `https://ghp_…@github.com` → `https://[redacted]@github.com`), the
  segment after `/reset/`, `/verify/`, `/confirm/`, `/invite/`, `/token/`, `/unsubscribe/` and
  similar, and the values of route parameters with sensitive names or named `hash` or `nonce`
  (`/reset/{token}`, `/verify/{id}/{hash}`) in the request URL and its label. Outgoing request URLs also lose webhook secrets (Telegram
  `/bot<token>/`, Slack `/services/…`, Discord `/api/webhooks/<id>/<token>`) and any path
  segment that looks like a random token.
- **Secrets inside text**: in log messages, exception messages and string values in log
  context, every URL is redacted the same way; so are the value after `Authorization:` or
  `Proxy-Authorization:` (any scheme), credentials after `Bearer`, `Basic`, `Token`, `Bot`,
  `Digest` or `ApiKey`, JWTs (`eyJ….….…`), and the value of a sensitive name in
  `name=value`, `name: value`, `password:value`, JSON (`"password":"…"`) and PHP arrays
  (`'password' => '…'`, `[password] => …`).
- **Command lines**: the values of sensitive options (`--password=…`, `--token …`,
  `--password='a b'`, `-psecret`) and of `NAME=value` arguments with a sensitive name, and URLs
  in any argument. Other arguments are sent as given.
- **Headers listed in `LAMPWICK_REDACT_HEADERS`**, on top of the names above.
- **Payload fields listed in `LAMPWICK_REDACT_PAYLOAD_FIELDS`** (default
  `_token,password,password_confirmation,current_password,new_password,token,secret,api_key`).

The redaction runs through hooks of `laravel/nightwatch`; with a version that lacks them, the
package turns monitoring off instead of sending unredacted records.

Never sent at all: environment variables and configuration values, request bodies (unless `LAMPWICK_CAPTURE_REQUEST_PAYLOAD=true`; then only for 500 responses, with the rules above),
response bodies, query bindings (only the SQL with its placeholders), the values of function
arguments in stack traces (only their types), mail bodies and recipient addresses (only their
number), and the project token (it only goes from the agent to Lampwick, over HTTPS).

What is sent as written: SQL text, cache keys, mail subjects, log and exception messages apart
from the URLs in them, and the source lines of your own files around an exception
(`LAMPWICK_CAPTURE_EXCEPTION_SOURCE_CODE=false` turns them off; files under `vendor/`, `var/`,
`bootstrap/cache/`, `storage/` and `.env*` files are never read). Keep secrets out of those, as
you would for any log. Lampwick's ingest also drops `Authorization`, `Cookie` and other
credential headers itself, as a second line of defence.

## Run the agent in production

The agent is a long-running process, like a queue worker: run one per server, next to your app,
and let a process manager keep it running. Restart it after deploys so it picks up changed
configuration (`php artisan lampwick:agent` reads `.env` and config at start-up).

### Laravel Forge

Server → **Daemons** → New daemon:

- Command: `php artisan lampwick:agent`
- Directory: `/home/forge/example.com/current` (your site's directory)
- User: `forge`
- Stop wait seconds: `15`

### Supervisor

```ini
[program:lampwick-agent]
command=php /var/www/example.com/artisan lampwick:agent
directory=/var/www/example.com
user=www-data
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=15
redirect_stderr=true
stdout_logfile=/var/log/lampwick-agent.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
```

On `SIGTERM` (or `SIGINT`) the agent stops accepting records, sends what is buffered and exits,
within `LAMPWICK_AGENT_SHUTDOWN_TIMEOUT` seconds (10 by default). Keep `stopwaitsecs` above that.

### Docker and multiple servers

Each server (or container) that runs PHP needs its own agent. Run the agent in the same
container as the app (for example as a second process under Supervisor), so it keeps listening
on `127.0.0.1`.

The agent's port takes data from anyone who can connect and nothing on it is secret (the token
hash in a payload is 7 hex characters), so never publish it (`ports:` in Compose, `-p` in
`docker run`), never bind it to a public interface, and don't share it between apps. If the
agent must run in a separate container, put both on an internal network of their own (Compose
`internal: true`), set `LAMPWICK_AGENT_LISTEN` to the agent's address there in both, and keep
the port unpublished.

## Record deploys

Run `lampwick:deploy` as the last step of your deploy (Forge deploy script, Envoyer hook, CI):

```bash
php artisan lampwick:deploy            # version: LAMPWICK_DEPLOY, or the current git commit
php artisan lampwick:deploy v1.4.0 --author="$FORGE_DEPLOY_AUTHOR"
```

Lampwick puts a marker on its charts and ties new errors to the deploy ("first seen in v1.4.0",
"new since last deploy"). It sends the current git commit, the `origin` remote (to link the
commit) and the app environment along; override them with `--commit`, `--repo` and
`--environment`. It uses the same `LAMPWICK_TOKEN` and needs no agent. On GitHub Actions you can
use the Lampwick deploy action instead (`oosterhoff/lampwick/integrations/github-action@deploy-action-v1`).

## Configuration

Everything is set with environment variables. To change the defaults in code instead, publish
the config file: `php artisan vendor:publish --tag=lampwick-config`.

| Variable | Default | |
| --- | --- | --- |
| `LAMPWICK_TOKEN` | — | Project token. Without it nothing is captured. |
| `LAMPWICK_URL` | `https://lampwick.dev` | Lampwick address. Must be `https://` (plain `http://` only to this machine: `localhost`, `127.0.0.1`, `::1`). |
| `LAMPWICK_ENABLED` | `true` | `false` turns the instrumentation off. |
| `LAMPWICK_AGENT_LISTEN` | `127.0.0.1:2407` | Address the agent listens on and the app sends to. |
| `LAMPWICK_AGENT_FLUSH_INTERVAL` | `1.0` | Seconds a record may wait before its batch is sent. |
| `LAMPWICK_AGENT_BATCH_BYTES` | `2097152` | Send a batch early once it reaches this size. |
| `LAMPWICK_AGENT_BUFFER_BYTES` | `67108864` | Memory for records while Lampwick is unreachable; the oldest are dropped beyond this. |
| `LAMPWICK_AGENT_SHUTDOWN_TIMEOUT` | `10` | Seconds to flush on stop. |
| `LAMPWICK_CA_BUNDLE` | system | PEM file with CA certificates, only needed behind a TLS-intercepting proxy. |
| `LAMPWICK_SERVER` | hostname | Server name shown in Lampwick. |
| `LAMPWICK_DEPLOY` | Forge/Vapor/Cloud commit | Deployment identifier. |
| `LAMPWICK_REQUEST_SAMPLE_RATE` | `1.0` | Share of requests to capture (0.0–1.0). |
| `LAMPWICK_COMMAND_SAMPLE_RATE` | `1.0` | Same, for artisan commands. |
| `LAMPWICK_EXCEPTION_SAMPLE_RATE` | `1.0` | Same, for exceptions outside sampled requests. |
| `LAMPWICK_SCHEDULED_TASK_SAMPLE_RATE` | `1.0` | Same, for scheduled tasks. |
| `LAMPWICK_IGNORE_QUERIES` | `false` | Don't capture database queries. |
| `LAMPWICK_IGNORE_CACHE_EVENTS` | `false` | Don't capture cache hits/misses/writes. |
| `LAMPWICK_IGNORE_MAIL` | `false` | Don't capture mail. |
| `LAMPWICK_IGNORE_NOTIFICATIONS` | `false` | Don't capture notifications. |
| `LAMPWICK_IGNORE_OUTGOING_REQUESTS` | `false` | Don't capture outgoing HTTP requests. |
| `LAMPWICK_LOG_LEVEL` | `LOG_LEVEL` | Lowest log level sent through the `lampwick` channel. |
| `LAMPWICK_CAPTURE_EXCEPTION_SOURCE_CODE` | `true` | Include source lines around exceptions. |
| `LAMPWICK_CAPTURE_REQUEST_PAYLOAD` | `false` | Include request bodies (redacted fields below). |
| `LAMPWICK_REDACT_PAYLOAD_FIELDS` | `_token,password,password_confirmation,current_password,new_password,token,secret,api_key` | Comma-separated; on top of the rules in *What is never sent*. |
| `LAMPWICK_REDACT_HEADERS` | `Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN,X-CSRF-TOKEN,X-Api-Key,X-Auth-Token` | Comma-separated; on top of the rules in *What is never sent*. |

The variable names of the underlying instrumentation keep working; `LAMPWICK_*` values win.

## How the agent behaves

- It answers the app as soon as a payload is received, so the app is never slowed down by
  Lampwick or the network. If the agent is not running, stuck or not reading, the app waits at
  most about 1 second (the instrumentation's connection timeout plus its timeout) and keeps working; records from that period are lost.
  After a failure the app stops trying for 10 seconds, so a long command or queue worker loses
  at most one timeout per 10 seconds.
- One HTTPS request is in flight at a time; records keep their order.
- `503`, `429`, other `5xx` and network errors are retried, honouring `Retry-After`, otherwise
  with exponential backoff up to 60 seconds. A `413` splits the batch. Other `4xx` answers
  (for example `401` for a wrong token) drop the batch and are logged as errors.
- When your team has used its monthly events, Lampwick answers `429` until the next month or
  an upgrade. The agent then keeps the newest records buffered (up to its buffer size, oldest
  dropped first) and tries again every minute; the app itself is not affected.
- Payloads whose token does not match `LAMPWICK_TOKEN` are refused, so two apps on one server
  can't mix up their data: give each app its own `LAMPWICK_AGENT_LISTEN` port.
- It logs to stdout/stderr: warnings at most once a minute per kind, and a summary every five
  minutes.
- Certificates are always verified. The token only ever leaves the server over HTTPS.

## Troubleshooting

- **Nothing shows up**: run `php artisan lampwick:test`. Check that the agent runs with the same
  `.env` as the app and that `LAMPWICK_TOKEN` is set in both.
- **`address already in use`**: another agent (or another monitoring agent) already listens on
  that port. Stop it or pick another `LAMPWICK_AGENT_LISTEN` (the app and agent read the same
  setting).
- **`HTTP 401`**: the token is wrong or the project was deleted; copy it again from the
  project's settings page.
- **Config cached**: after changing `.env`, run `php artisan config:cache` again and restart
  the agent.

## Development

```bash
composer install
vendor/bin/phpunit
```

In the Lampwick monorepo, Composer takes the shared core (`lampwick/php-agent`, the agent,
client and record contract) from `../php-agent`.

The contract tests (`tests/Contract`) run a small Laravel app (`tests/Contract/App`: a route,
a command, a queued job and a scheduled task) as real processes — `php -S`, `queue:work`,
`schedule:run` — through a real `lampwick:agent` to a fake HTTP ingest, and check every record
against the format Lampwick reads. The supported `laravel/nightwatch` versions are pinned to the
range these tests pass on (`composer.json`); CI runs them on the lowest and highest version of
that range, and once a week on the newest Nightwatch release, so a release that changes the
record format shows up in CI before the range is widened.

## License

MIT
