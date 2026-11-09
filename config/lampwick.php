<?php

$list = static fn (?string $value): ?array => $value === null ? null : array_values(array_filter(array_map('trim', explode(',', $value))));

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The project token from the project's settings page in lampwick, and the
    | lampwick address. Records are sent to "{url}/ingest" over HTTPS with the
    | full token; they never leave the server unencrypted.
    |
    */

    'token' => env('LAMPWICK_TOKEN'),

    'url' => env('LAMPWICK_URL', 'https://lampwick.dev'),

    'enabled' => env('LAMPWICK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Local agent
    |--------------------------------------------------------------------------
    |
    | Your app hands its records to `php artisan lampwick:agent` on this local
    | address (a quick local TCP write, so requests never wait on the
    | network). The agent batches them and forwards them to lampwick.
    | Keep it on a loopback or private address.
    |
    */

    'agent' => [
        'listen' => env('LAMPWICK_AGENT_LISTEN', '127.0.0.1:2407'),

        // Send a batch once this many seconds passed since its first record...
        'flush_interval' => (float) env('LAMPWICK_AGENT_FLUSH_INTERVAL', 1.0),

        // ...or once it reaches this size (lampwick accepts up to 16 MB per request).
        'batch_bytes' => (int) env('LAMPWICK_AGENT_BATCH_BYTES', 2 * 1024 * 1024),

        // Upper bound for records buffered while lampwick is unreachable; the oldest are dropped first.
        'buffer_bytes' => (int) env('LAMPWICK_AGENT_BUFFER_BYTES', 64 * 1024 * 1024),

        // Largest single payload accepted from the app.
        'max_frame_bytes' => (int) env('LAMPWICK_AGENT_MAX_FRAME_BYTES', 8 * 1024 * 1024),

        'max_connections' => (int) env('LAMPWICK_AGENT_MAX_CONNECTIONS', 256),

        // Seconds to keep flushing on SIGTERM/SIGINT before exiting.
        'shutdown_timeout' => (float) env('LAMPWICK_AGENT_SHUTDOWN_TIMEOUT', 10.0),

        // Seconds per HTTPS request.
        'connect_timeout' => (float) env('LAMPWICK_AGENT_CONNECT_TIMEOUT', 5.0),
        'timeout' => (float) env('LAMPWICK_AGENT_TIMEOUT', 30.0),

        // CA certificates (PEM) to verify lampwick's certificate with, instead of the system's.
        // Only needed behind a TLS-intercepting proxy or for a lampwick with a private CA.
        'ca_bundle' => env('LAMPWICK_CA_BUNDLE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | What is captured
    |--------------------------------------------------------------------------
    |
    | Null means "use the default". Sample rates are between 0.0 and 1.0.
    |
    */

    'server' => env('LAMPWICK_SERVER'),

    'deployment' => env('LAMPWICK_DEPLOY'),

    'sampling' => [
        'requests' => env('LAMPWICK_REQUEST_SAMPLE_RATE'),
        'commands' => env('LAMPWICK_COMMAND_SAMPLE_RATE'),
        'exceptions' => env('LAMPWICK_EXCEPTION_SAMPLE_RATE'),
        'scheduled_tasks' => env('LAMPWICK_SCHEDULED_TASK_SAMPLE_RATE'),
    ],

    'filtering' => [
        'ignore_cache_events' => env('LAMPWICK_IGNORE_CACHE_EVENTS'),
        'ignore_mail' => env('LAMPWICK_IGNORE_MAIL'),
        'ignore_notifications' => env('LAMPWICK_IGNORE_NOTIFICATIONS'),
        'ignore_outgoing_requests' => env('LAMPWICK_IGNORE_OUTGOING_REQUESTS'),
        'ignore_queries' => env('LAMPWICK_IGNORE_QUERIES'),
        'log_level' => env('LAMPWICK_LOG_LEVEL'),
    ],

    'capture_exception_source_code' => env('LAMPWICK_CAPTURE_EXCEPTION_SOURCE_CODE'),

    'capture_request_payload' => env('LAMPWICK_CAPTURE_REQUEST_PAYLOAD'),

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Values of these headers and payload fields are never sent. On top of
    | these lists, any header, payload field, query parameter, log context
    | key or command option whose name contains password, secret, token,
    | key, auth, cookie, session, ... is redacted (Lampwick\Agent\Redactor).
    |
    */

    'redact_payload_fields' => $list(env('LAMPWICK_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation,current_password,new_password,token,secret,api_key')),

    'redact_headers' => $list(env('LAMPWICK_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN,X-CSRF-TOKEN,X-Api-Key,X-Auth-Token')),

];
