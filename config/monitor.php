<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    | Master switch for reporting. When false, no data leaves the app.
    */
    'enabled' => env('MONITOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Server URL & project key
    |--------------------------------------------------------------------------
    | The base URL of your Quiet Guard server and the per-project API key
    | generated in its dashboard.
    */
    'url' => env('MONITOR_URL'),

    'key' => env('MONITOR_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    | Only report when the current app environment is in this list. Leave empty
    | to report from every environment.
    */
    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITOR_ENVIRONMENTS', 'production,staging'))))),

    /*
    |--------------------------------------------------------------------------
    | Ignored request paths
    |--------------------------------------------------------------------------
    | Request paths whose exceptions are never reported, written in the syntax
    | Illuminate\Http\Request::is() accepts ("api/v1/*"). Empty by default.
    |
    | An application that hosts a monitoring endpoint of its own should not
    | report to that same endpoint the exceptions it raises while serving it.
    | Not a loop, the transport swallows its own failures, but pointless
    | amplification at the worst possible moment.
    */
    'ignore_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    | An identifier for the deployed version (e.g. a git SHA or tag). Helps
    | attribute issues to a specific deploy.
    */
    'release' => env('MONITOR_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    | "timeout" is the HTTP timeout in seconds. When "queue" is not false the
    | payload is pushed onto the given queue connection instead of being sent
    | synchronously, so reporting never blocks the request.
    */
    'timeout' => (int) env('MONITOR_TIMEOUT', 3),

    'queue' => env('MONITOR_QUEUE', false),

    /*
    |--------------------------------------------------------------------------
    | Stack trace depth
    |--------------------------------------------------------------------------
    */
    'trace_limit' => (int) env('MONITOR_TRACE_LIMIT', 0), // 0 = full stack trace (recommended); set a frame count to trim payloads

    /*
    |--------------------------------------------------------------------------
    | Application logs
    |--------------------------------------------------------------------------
    | When enabled, log messages at or above "level" are buffered during the
    | request and sent to the monitor server in a single batch. Log entries that
    | carry an exception are ignored (they go through the exception pipeline).
    */
    'logs' => [
        'enabled' => env('MONITOR_LOGS_ENABLED', false),
        'level' => env('MONITOR_LOG_LEVEL', 'warning'),
        'max_batch' => (int) env('MONITOR_LOGS_MAX_BATCH', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrubbed keys
    |--------------------------------------------------------------------------
    | Request/context keys whose values are masked before leaving the app.
    */
    /*
    | Value masking, by shape rather than by field name.
    |
    | 'scrub' below hides a value because of what its field is CALLED. It never
    | sees an address written into the free text of an error message, into a URL
    | segment, or into a field somebody named "reference", which is the larger
    | share of what actually leaks.
    |
    | These patterns look at the value itself, before anything is sent, so the
    | data never reaches the monitoring server at all. The shapes that carry a
    | checksum are verified rather than merely matched, so a sixteen digit order
    | reference is not mistaken for a card number.
    |
    | Available: email, iban, nir (French social security), card, phone (French
    | numbering plan). Remove the ones that produce false positives on your data,
    | or set the whole array to [] to send payloads untouched.
    */
    'redact' => ['email', 'iban', 'nir', 'card', 'phone'],

    /*
    | Your own shapes: label => PCRE pattern. The label appears in the payload,
    | for example 'customer_ref' masks as [redacted:customer_ref].
    */
    'redact_custom' => [],

    'scrub' => [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'authorization',
        'cookie',
        'php_auth_pw',
        'api_key',
        'access_token',

        // The headers that carry a visitor's IP address.
        //
        // The payload sends every header it finds, and behind a reverse proxy,
        // which is the ordinary production topology, one of these holds the IP
        // of the person who hit the page. That is personal data, and it does
        // not identify OUR customer: it identifies THEIR visitor, who never
        // chose us and whose address an exception report does not need.
        //
        // Masked by NAME rather than by shape. ValueRedactor works on shapes
        // and has no IP pattern on purpose: a dotted quad is indistinguishable
        // from a version string without context, and a pattern that masks too
        // much is a pattern somebody switches off. The header name is exact.
        'x-forwarded-for',
        'x-real-ip',
        'cf-connecting-ip',
        'true-client-ip',
        'x-client-ip',
        'forwarded',
    ],
];
