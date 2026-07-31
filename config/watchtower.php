<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Watchtower Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, no listeners record anything and the repository becomes a
    | no-op. The dashboard still renders (so you can read historical data) but
    | nothing new is written. Safe to leave enabled in production.
    |
    */

    'enabled' => env('WATCHTOWER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Route
    |--------------------------------------------------------------------------
    |
    | "path" is the URI prefix the dashboard + JSON API are served from.
    | "domain" optionally scopes the routes to a subdomain. "middleware" is the
    | stack every Watchtower route runs through (the Authorize middleware that
    | checks the "viewWatchtower" gate is appended automatically).
    |
    */

    'path' => env('WATCHTOWER_PATH', 'watchtower'),

    'domain' => env('WATCHTOWER_DOMAIN'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Storage Connection & Tables
    |--------------------------------------------------------------------------
    |
    | "connection" lets Watchtower's tables live on a dedicated database so its
    | writes never contend with your application's traffic. null = the default
    | connection. "table_prefix" namespaces every table it creates.
    |
    */

    'connection' => env('WATCHTOWER_DB_CONNECTION'),

    'table_prefix' => env('WATCHTOWER_TABLE_PREFIX', 'watchtower_'),

    /*
    |--------------------------------------------------------------------------
    | Recording
    |--------------------------------------------------------------------------
    |
    | Toggle each subsystem independently. Disabling one stops its listeners
    | from writing without affecting the others.
    |
    */

    'recording' => [
        'schedule' => env('WATCHTOWER_RECORD_SCHEDULE', true),
        'queue' => env('WATCHTOWER_RECORD_QUEUE', true),
        'exceptions' => env('WATCHTOWER_RECORD_EXCEPTIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write Strategy
    |--------------------------------------------------------------------------
    |
    | "after_response" defers metric writes to the framework's terminating()
    | callback so request latency is untouched (recommended). Console contexts
    | (queue workers, artisan) always write inline, since terminating() there
    | only fires when the process exits. Sampling stores only a fraction of
    | records (1.0 = everything, 0.1 = ~10%). Failures and schedule runs are
    | always recorded regardless of the sample rate so you never miss the
    | things that matter.
    |
    */

    'writes' => [
        'after_response' => env('WATCHTOWER_AFTER_RESPONSE', true),
    ],

    'sampling' => [
        'rate' => (float) env('WATCHTOWER_SAMPLING_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (days)
    |--------------------------------------------------------------------------
    |
    | The watchtower:prune command deletes records older than these windows so
    | the database never grows unbounded. Schedule it to run daily.
    |
    */

    'retention' => [
        'schedule' => env('WATCHTOWER_RETAIN_SCHEDULE', 30),
        'queue' => env('WATCHTOWER_RETAIN_QUEUE', 7),
        'exceptions' => env('WATCHTOWER_RETAIN_EXCEPTIONS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Byte Limits
    |--------------------------------------------------------------------------
    |
    | Everything stored is truncated to these caps to bound row size. Set
    | "store_payload" to false to never persist job payloads (for apps with
    | sensitive job data).
    |
    */

    'limits' => [
        'trace' => env('WATCHTOWER_LIMIT_TRACE', 16384),
        'payload' => env('WATCHTOWER_LIMIT_PAYLOAD', 8192),
        'output' => env('WATCHTOWER_LIMIT_OUTPUT', 8192),
        'message' => env('WATCHTOWER_LIMIT_MESSAGE', 2048),
        'store_payload' => env('WATCHTOWER_STORE_PAYLOAD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignore Lists
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class names (jobs / exceptions) and command signatures to
    | skip entirely. Useful for noisy health-check jobs or expected exceptions.
    |
    */

    'ignore' => [
        'jobs' => [],
        'commands' => [],
        'exceptions' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    'dashboard' => [
        'polling_interval' => env('WATCHTOWER_POLL_INTERVAL', 5000),
        'per_page' => env('WATCHTOWER_PER_PAGE', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts (off by default)
    |--------------------------------------------------------------------------
    |
    | Opt-in notifications. Each channel is independent. Thresholds control the
    | failed-job alert.
    |
    */

    'alerts' => [
        'enabled' => env('WATCHTOWER_ALERTS_ENABLED', false),

        'channels' => [
            'slack' => env('WATCHTOWER_SLACK_WEBHOOK'),
            'webhook' => env('WATCHTOWER_WEBHOOK_URL'),
            'mail' => array_filter(explode(',', (string) env('WATCHTOWER_ALERT_MAIL', ''))),
        ],

        'on' => [
            'schedule_failed' => true,
            'schedule_missed' => true,
            'failed_jobs_threshold' => true,
        ],

        'failed_jobs' => [
            'threshold' => env('WATCHTOWER_FAILED_THRESHOLD', 25),
            'window_minutes' => env('WATCHTOWER_FAILED_WINDOW', 60),
        ],
    ],

];
