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
    // Empty means the hosted service. That default is applied by the client,
    // not by this file, which is a copy frozen at the version you published
    // it from. Set MONITOR_URL only for a self-hosted instance, without a
    // path: the client adds /api/v1 itself and strips a trailing /api or
    // /api/vN.
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
    | attribute issues to a specific deploy, and a full commit SHA is what the
    | server links to a synced GitHub commit.
    |
    | Leave it empty and the SDK finds it by itself: a REVISION file at the
    | application root (Capistrano, Deployer and most deploy scripts write one),
    | else the checkout's own .git/HEAD. Set MONITOR_RELEASE to override, or
    | MONITOR_RELEASE_AUTO=false to send only what you configured, for instance
    | when the checkout on the server is not the branch that actually runs.
    | Detection reads the files once per process, never on every exception.
    */
    'release' => env('MONITOR_RELEASE'),

    'release_auto' => (bool) env('MONITOR_RELEASE_AUTO', true),

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
    | Source snippets
    |--------------------------------------------------------------------------
    | A few lines of your own code around each application frame (never a
    | dependency's), so the dashboard shows the failing line in context the
    | way Laravel's exception page does. Set it to false and only file and
    | line travel. Sealed at rest with the rest of the trace when the team's
    | encryption is on.
    */
    'code_snippets' => (bool) env('MONITOR_CODE_SNIPPETS', true),

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
    | Automatic heartbeats
    |--------------------------------------------------------------------------
    |
    | Two silences nothing else in the SDK can see, because neither raises an
    | exception: a scheduler that stopped and a worker that died. Nothing is
    | added to your application, no scheduled entry and no job; the server
    | arms each heartbeat on its first ping. An application without a
    | scheduler or a worker never creates one, so it is never alerted.
    |
    | `scheduler`: each `schedule:run` pings a `scheduler` heartbeat as it
    | starts, maintenance mode included. The first run only records the time
    | and the second pings with the gap observed between the two, so a cron
    | every five minutes, or hourly, is expected at that pace, with that gap
    | plus four minutes of grace. A run typed in a terminal is ignored. The
    | time is kept in your default cache store, which must outlive the
    | process: on the `array` store the scheduler sends nothing. Several
    | hosts on one shared cache count as one: a start less than thirty
    | seconds from the recorded one is taken for that same start.
    |
    | `queues`: every `queue:work` (Horizon included) pings one heartbeat per
    | queue it consumes, at most once a minute across the workers that share
    | the cache, even while the queue is empty, with the shortest wait it saw
    | between a job being queued and a worker taking it (Laravel 12.11 and
    | later). true (the default) watches every queue a worker consumes, false
    | none, and a list such as "redis:default,redis:emails" only those pairs;
    | a name without a colon is a connection (every queue of it) or a queue
    | (on any connection), so "default,emails" works as written. A worker
    | pings between two jobs only, so on Laravel 13.10 and later its
    | --timeout plus a minute sets how long its queue may stay silent, nine
    | minutes at least; earlier versions keep nine minutes. `queue:listen`
    | and `queue:work --once` do not loop, so they ping nothing, and neither
    | does a worker paused (horizon:pause, SIGUSR2) or stopped by maintenance
    | mode. A worker whose ping fails sends no queue ping for a minute.
    |
    | `environments`: on top of MONITOR_ENVIRONMENTS, and narrower on purpose,
    | since a staging box switched off at night would alert every evening.
    |
    */
    'heartbeats' => [
        'scheduler' => env('MONITOR_SCHEDULER_HEARTBEAT', true),
        'queues' => env('MONITOR_QUEUE_HEARTBEATS', true),
        'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITOR_HEARTBEAT_ENVIRONMENTS', 'production'))))),
    ],

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
    | Personal data: email, iban, nir (French social security), card, phone
    | (French numbering plan). Credentials, since 0.6.0: private_key (a
    | -----BEGIN ... PRIVATE KEY----- block), jwt, stripe (sk_live_, sk_test_,
    | rk_live_, rk_test_, whsec_), aws (an AKIA/ASIA access key id) and
    | aws_secret (the secret key, only within eighty characters after such an
    | id or after the name aws_secret_access_key), github (ghp_, gho_, ghu_,
    | ghs_, ghr_, github_pat_), gitlab (glpat-, glrt-, gldt-, gloas-, glptt-,
    | glffct-, glimt-, glagent-, glcbt-, glsoat-), slack (xox?-, xapp-, and
    | the path of a hooks.slack.com webhook), app_key (a Laravel APP_KEY,
    | base64:...), project_key (a Quiet Guard lm_ key) and url_password (the
    | password of a user:password@host in an address of any scheme, the
    | scheme, the user and the host staying readable).
    |
    | null means the client's own list, every shape above and whatever a
    | later version adds, so a published copy of this file follows the
    | package and names no class of it (removing the package never makes this
    | file fatal). To remove a shape, write the list out:
    | 'redact' => ['email', 'iban', 'card', ...]. The exact list this file
    | shipped before 0.6.0, ['email', 'iban', 'nir', 'card', 'phone'], is
    | read as the default it was, since every published copy carries it. Set
    | it to [] to send payloads untouched: Quiet Guard still masks the
    | credential shapes on arrival, never the personal ones, which are yours
    | to decide.
    |
    | A value the engine cannot scan (a PCRE limit hit on a built-in shape) is
    | replaced whole by [redacted:unscannable] rather than sent as it came.
    */
    'redact' => null,

    /*
    | Your own shapes: label => PCRE pattern. The label appears in the payload,
    | for example 'customer_ref' masks as [redacted:customer_ref]. A pattern
    | that does not compile is ignored: that is a configuration error, not an
    | unreadable value, and it must not stop a report.
    */
    'redact_custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Request headers sent in clear
    |--------------------------------------------------------------------------
    | Since 0.6.0 an exception report carries every header of the request by
    | NAME, and only the ones named here by VALUE; the others travel as
    | [scrubbed]. An allow list rather than a block list: a block list names
    | the headers somebody thought of, and the next proxy or SDK adds one
    | nobody did. These say what the request was and how it was asked for,
    | and none of them has ever carried a credential. The scrub list below
    | still applies on top: a name it matches is masked whatever this list
    | says.
    |
    | The headers that carry the visitor's IP address (x-forwarded-for,
    | x-real-ip, cf-connecting-ip, true-client-ip, x-client-ip, forwarded)
    | are deliberately absent: they are personal data, and keeping them is
    | your decision as controller, which the processing agreement leaves to
    | you. Add them here, and remove them from the scrub list below, to keep
    | them. referer and referrer stay masked: a URL is where applications put
    | their one-off secrets.
    */
    'request_headers' => [
        'accept',
        'accept-encoding',
        'accept-language',
        'cache-control',
        'content-length',
        'content-type',
        'host',
        'origin',
        'user-agent',
        'x-requested-with',
        'x-livewire',
        'x-inertia',
        'x-inertia-version',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scrubbed keys
    |--------------------------------------------------------------------------
    | Names whose values are masked before leaving the app, matched by
    | case-insensitive substring, a hyphen counting as an underscore
    | (php_auth_pw masks the php-auth-pw header): a key of the request or the
    | context, a key inside a value that is itself JSON (a Livewire snapshot),
    | and a parameter of a URL's query string, the reported URL included. The
    | path of a URL is read too: a segment made only of letters and digits,
    | forty or more, is masked whatever this list says, since that is where a
    | reset link keeps its token (/reset-password/{token}); a number, a slug,
    | a UUID or a ULID stays. A route whose path holds a secret of another
    | shape can go under ignore_paths, which drops its exception reports
    | whole rather than masking them. A line of a source snippet that gives
    | one of these names a value, or one of a few words of the SDK's own
    | (key, auth, bearer...), is replaced whole by [scrubbed].
    */
    // Removing a name from your copy lets its value leave the application,
    // and with MONITOR_QUEUE it is first written to your jobs table (and to
    // failed_jobs if the send fails), since reports are masked before they
    // are queued. Quiet Guard masks the request context of an exception
    // report again when it arrives, with a list of its own: a secret of this
    // default list is masked there before anything is stored, but not the
    // visitor IP headers below, which are yours to keep or mask. Log
    // contexts and the lines of a code snippet are masked by this list
    // alone, on your side, and so is any name you add; what Quiet Guard
    // masks there on arrival is the credential SHAPES of 'redact' above
    // (a stamped token, a key block, a URL password), by shape and not by
    // name.
    'scrub' => [
        'password',
        'password_confirmation',
        // An encryption passphrase is the one value the platform is designed
        // never to hold: it derives the key that opens a team's sealed data.
        // Substring matching covers 'passphrase_confirmation' and the nested
        // 'mountedActions.0.data.passphrase' a Livewire request carries.
        'passphrase',
        'token',
        // The parameter that authorises a URL Laravel signed (URL::signedRoute).
        'signature',
        'secret',
        'authorization',
        'cookie',
        'php_auth_pw',
        'api_key',
        'access_token',

        // The address of the page they came FROM.
        //
        // A referrer is a URL, and a URL is where applications put their
        // one-off secrets: an unsubscribe link, a signed download, a password
        // reset, a share link. This platform's own status page is addressed by
        // a token that IS its access control, and a single click from it to
        // anywhere else on the same site sent that token in this header, since
        // Referrer-Policy: strict-origin-when-cross-origin sends the full URL
        // same-origin. The next exception filed it in the operator's project.
        //
        // Masked by name like the IP headers below, and for the same reason:
        // the value has no shape a redactor could recognise.
        'referer',
        'referrer',

        // The headers that carry a visitor's IP address.
        //
        // Behind a reverse proxy, which is the ordinary production topology,
        // one of these holds the IP of the person who hit the page. Since
        // 0.6.0 'request_headers' above already withholds their value (only
        // the names it lists travel in clear); they stay here so that a copy
        // of this file published before that list existed masks them too. That is personal data, and it does
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
