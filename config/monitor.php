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
    ],
];
