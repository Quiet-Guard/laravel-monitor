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
    // Le service hébergé est le cas normal, donc il est le défaut : un client
    // qui installe le paquet n'a que sa clé à coller. Écrasable par
    // MONITOR_URL pour une instance auto-hébergée.
    //
    // Le SDK ajoute /api/v1 lui-même. Une valeur qui le contient déjà est
    // acceptée et normalisée (Transport::baseUrl) : c'est l'erreur qu'on fait
    // en lisant « l'URL de l'API » dans la documentation, elle est donc rendue
    // impossible plutôt que documentée.
    // Laissée vide, elle vaut le service hébergé : le défaut est appliqué par
    // le client, pas par ce fichier. Ce fichier est une COPIE, figée à la
    // version où vous l'avez publié, et il gagne sur les défauts du paquet ;
    // un défaut écrit ici seulement n'atteindrait donc aucune installation
    // existante. Ne mettez pas de chemin : le client ajoute /api/v1 lui-même,
    // et retire celui que vous auriez laissé.
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
    | Scrubbed keys
    |--------------------------------------------------------------------------
    | Names whose values are masked before leaving the app, matched by
    | case-insensitive substring: a key of the request or the context, a key
    | inside a value that is itself JSON (a Livewire snapshot), and a
    | parameter of a URL's query string, the reported URL included. The path
    | of a URL is not: list a route whose path is itself a secret under
    | ignore_paths. A line of a source snippet that gives one of these names a
    | value goes whole.
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
