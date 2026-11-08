# lampwick for Laravel

Send requests, queries, jobs, exceptions, logs and more from your Laravel app to
[lampwick](https://lampwick.dev), over HTTPS.

```
your app ──local TCP──▶ php artisan lampwick:agent ──HTTPS + project token──▶ https://lampwick.dev/ingest
```

Your app hands its records to a small agent on the same server (a quick write to
`127.0.0.1:2407`, so requests never wait on the network). The agent batches them and sends them
to lampwick over HTTPS, authenticated with your full project token. If lampwick is briefly
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

Use your project token from the project's **Settings** page in lampwick. Open your app; within a
few seconds the request shows up in lampwick. In production, keep the agent running under a
process manager (below).

`php artisan lampwick:test` checks the token, the network and the agent. To also send your
application logs, add the `lampwick` channel to your log stack: `LOG_STACK=single,lampwick`.

## What is never sent

Before anything leaves your app, these values are replaced by `[redacted]`:

- **Sensitive names**, everywhere a name comes with a value (request headers, request payload
  fields, URL query parameters, log context and extra, command options): any name containing
  `password`, `passwd`, `passphrase`, `secret`, `token`, `apikey`, `accesskey`, `privatekey`,
  `auth`, `credential`, `cookie`, `session`, `sessid`, `signature`, `csrf`, `xsrf`, `otp`,
  `cvv`, `cvc`, `cardnumber`, `iban`, `ssn` or `bearer`, and the names `pin`, `sig`, `code`,
  `key`, `pwd`, `pass` and `jwt` (case, `-` and `_` ignored). So `Authorization`, `Cookie`,
  `X-Auth-Token`, `?api_key=`, `?code=` (OAuth), `['password' => ...]` and
  `--password=...` are all redacted. Nested keys too (`user[api_token]`).
- **URL passwords**: `https://user:secret@host` becomes `https://user:[redacted]@host`, in
  request and outgoing request URLs and in URLs inside log context.
- **Headers listed in `LAMPWICK_REDACT_HEADERS`**, on top of the names above.
- **Payload fields listed in `LAMPWICK_REDACT_PAYLOAD_FIELDS`** (default
  `_token,password,password_confirmation,current_password,new_password,token,secret,api_key`).

Never sent at all: environment variables and configuration values, request bodies (unless `LAMPWICK_CAPTURE_REQUEST_PAYLOAD=true`; then only for 500 responses, with the rules above),
response bodies, query bindings (only the SQL with its placeholders), the values of function
arguments in stack traces (only their types), mail bodies and recipient addresses (only their
number), and the project token (it only goes from the agent to lampwick, over HTTPS).

What is sent as written: exception messages, SQL text, cache keys, log messages and, unless you
set `LAMPWICK_CAPTURE_EXCEPTION_SOURCE_CODE=false`, the source lines of your own files around an
exception. Keep secrets out of those, as you would for any log. lampwick's ingest also drops
`Authorization`, `Cookie` and other credential headers itself, as a second line of defence.

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

Each server (or container) that runs PHP needs an agent it can reach. In containers, either run
the agent in the same container, or run it as a sidecar and point the app at it with
`LAMPWICK_AGENT_LISTEN` (for example `0.0.0.0:2407` for the agent and `agent:2407` for the app).
Never expose the agent's port to the internet: it accepts data from anyone who can reach it.

## Configuration

Everything is set with environment variables. To change the defaults in code instead, publish
the config file: `php artisan vendor:publish --tag=lampwick-config`.

| Variable | Default | |
| --- | --- | --- |
| `LAMPWICK_TOKEN` | — | Project token. Without it nothing is captured. |
| `LAMPWICK_URL` | `https://lampwick.dev` | lampwick address. Must be `https://` (plain `http://` only for localhost). |
| `LAMPWICK_ENABLED` | `true` | `false` turns the instrumentation off. |
| `LAMPWICK_AGENT_LISTEN` | `127.0.0.1:2407` | Address the agent listens on and the app sends to. |
| `LAMPWICK_AGENT_FLUSH_INTERVAL` | `1.0` | Seconds a record may wait before its batch is sent. |
| `LAMPWICK_AGENT_BATCH_BYTES` | `2097152` | Send a batch early once it reaches this size. |
| `LAMPWICK_AGENT_BUFFER_BYTES` | `67108864` | Memory for records while lampwick is unreachable; the oldest are dropped beyond this. |
| `LAMPWICK_AGENT_SHUTDOWN_TIMEOUT` | `10` | Seconds to flush on stop. |
| `LAMPWICK_CA_BUNDLE` | system | PEM file with CA certificates, only needed behind a TLS-intercepting proxy. |
| `LAMPWICK_SERVER` | hostname | Server name shown in lampwick. |
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
  lampwick or the network. If the agent is not running, the app keeps working; records from
  that period are lost.
- One HTTPS request is in flight at a time; records keep their order.
- `503`, `429`, other `5xx` and network errors are retried, honouring `Retry-After`, otherwise
  with exponential backoff up to 60 seconds. A `413` splits the batch. Other `4xx` answers
  (for example `401` for a wrong token) drop the batch and are logged as errors.
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

In the lampwick monorepo, Composer takes the shared core (`lampwick/php-agent`, the agent,
client and record contract) from `../php-agent`.

The contract tests (`tests/Contract`) run a small Laravel app (`tests/Contract/App`: a route,
a command, a queued job and a scheduled task) as real processes — `php -S`, `queue:work`,
`schedule:run` — through a real `lampwick:agent` to a fake HTTP ingest, and check every record
against the format lampwick reads. The supported `laravel/nightwatch` versions are pinned to the
range these tests pass on (`composer.json`); CI runs them on the lowest and highest version of
that range, and once a week on the newest Nightwatch release, so a release that changes the
record format shows up in CI before the range is widened.

## License

MIT
