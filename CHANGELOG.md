# Changelog

All notable changes to `quiet-guard/laravel-monitor`.

## v0.7.1 (unreleased)

### Security

- A log context value that is an object is unwrapped before it is scrubbed:
  `jsonSerialize()` first (what `json_encode` would call), then `toArray()`,
  and any other `Stringable` gives its public properties, as `json_encode`
  did, never its string (that of a `Response` or a `Request` carries
  `Set-Cookie`, `Cookie` and `Authorization`). An object that throws while
  being unwrapped is replaced by its class name instead of losing the log
  line, a cycle between objects is cut (`[recursion]`), and a `Request`
  still gives its public properties rather than `all()`, so a field with
  an innocent name (`otp`, `pin`) does not start leaving with the fix. `Log::warning('x', ['input' =>
  collect($request->all())])` used to send the password in clear, and with
  `MONITOR_QUEUE` set it was also serialized into the application's `jobs`
  and `failed_jobs` tables.

## v0.7.0

### Added

- `php artisan monitor:test` sends one test exception right away, without
  the queue even when `MONITOR_QUEUE` is set, and prints whether the server
  accepted it, with the server's own reason when it did not (an invalid key,
  a suspended payment), and fails when the server accepted it without keeping
  it because the month's quota is used up. It also runs in an environment left out
  of `MONITOR_ENVIRONMENTS`, to check the connection from a local machine, and
  warns that real exceptions there are not reported.
- Browser errors. `@quietGuardBrowser` in a layout's `<head>` prints a small
  dependency-free script, inline, that reports the uncaught JavaScript errors
  and unhandled promise rejections of your pages to the project's browser
  endpoint: the page address without its query string or fragment, the user
  agent, the error and its stack, whether Livewire is on the page, and the
  Alpine expression that failed when there is one. At most ten reports per
  page load, the same error once. It needs `MONITOR_BROWSER_KEY`, the project's
  public browser key from the dashboard (never `MONITOR_KEY`), and follows the
  same `enabled` and `environments` gates as exceptions. The script carries
  the nonce Vite gives the page, for a strict Content-Security-Policy; add the
  server's origin to `connect-src`. No source maps: frames show the built
  bundle.

## v0.6.0

### Security

- Request headers go out on an allow list. `PayloadBuilder` sent
  `$request->headers->all()` whole and masked it by name, so any header the
  scrub list had not thought of travelled with its value. Every header now
  travels with its NAME, and with its value only when `monitor.request_headers`
  names it (by default `accept`, `accept-encoding`, `accept-language`,
  `cache-control`, `content-length`, `content-type`, `host`, `origin`,
  `user-agent`, `x-requested-with`, `x-livewire`, `x-inertia`,
  `x-inertia-version`); the others carry `[scrubbed]`. The scrub list still
  applies on top. The six visitor IP headers are absent from the list and a
  customer with a legal basis adds them, removing them from `scrub`
  (`forwarded` included, since it masks `x-forwarded-for` by substring).
- A route parameter whose NAME the scrub list matches, or that is a
  credential in an address (`hash`, `signature`: Laravel's own
  `/email/verify/{id}/{hash}`), is masked in the reported URL whatever its
  length (`/reset-password/{token}`): the path rule only knew a token by its
  shape, forty letters or digits, which stays as the safety net. Read from
  `$request->route()`, failing open on anything unexpected.
- The message is no longer cut hard at 8192 characters before masking, which
  let the first half of a token straddling the cut leave in clear: it is cut
  softly at a whitespace past the limit (`PayloadBuilder`, `LogCollector`),
  masked whole, then cut at the limit (`Transport::redact`, through the
  core's `Cut::before()` and `Cut::payload()`). A request context past the
  65 535 bytes the server keeps is replaced before masking by the server's
  own marker rather than scanned whole.
- Credentials are masked by their shape wherever they sit, the queued path
  included (the report is masked before it is dispatched), through the core's
  `ValueRedactor`: see the core's changelog for the shapes. The published
  configuration template now writes `'redact' => null`, the client's own
  list, so a published copy follows the package and names no class of it
  (removing the package never makes that file fatal); the exact list a copy
  published before this version carries, `['email', 'iban', 'nir', 'card',
  'phone']`, is read as the default it was, so it masks credentials too.
- The shapes of the query string and of a path segment that `fullUrl()`
  writes percent-encoded are masked in the reported URL, as they already were
  in the `query` array beside it, through the core's `ValueRedactor`, which
  now reads an encoded value decoded.

### Changed

- The core is required as `^0.6.0`: `PayloadBuilder` calls `Scrubber::masks()`
  and the report relies on `ValueRedactor::CREDENTIALS`.
- `PayloadBuilder` takes a sixth constructor argument, the headers sent in
  clear (null takes `PayloadBuilder::HEADERS`).

## v0.5.0

### Added

- `monitor:backup --exclude-table=<table>`, repeatable, with `--database`
  only. The table's structure stays in the backup and its rows do not: two
  `mysqldump` passes on MySQL and MariaDB (every structure, then every row but
  the excluded tables, each pass with `--single-transaction`),
  `--exclude-table-data` on PostgreSQL, and on SQLite a copy emptied of those
  tables then vacuumed, so the deleted rows are not left readable in its free
  pages. A name carrying anything but letters, digits, `_`, `$` and `-` stops
  the command before any request, rather than backing the table up anyway.

### Changed

- The core is required as `^0.5.0`. It carries no change of its own and is
  released at the same version, so the four packages of the family install
  as one.

## v0.4.0

### Added

- Automatic heartbeats. In production, the SDK pings a `scheduler` heartbeat
  each time `schedule:run` starts, at the cron's own cadence, and, for every
  watched queue, pings `queue-{connection}-{queue}` from the worker's own loop
  with the shortest wait a job saw since the previous ping (Laravel 12.11 or
  later). The scheduler heartbeat needs two runs of `schedule:run` to arm (the
  first only records the time), and several hosts sharing one cache count as
  one scheduler; a queue heartbeat arms on a worker's first loop turn, with a
  grace that follows the worker's `--timeout` on Laravel 13.10 or later.
  `MONITOR_SCHEDULER_HEARTBEAT`, `MONITOR_QUEUE_HEARTBEATS` and
  `MONITOR_HEARTBEAT_ENVIRONMENTS` control them. A worker whose ping fails
  sends no queue ping for a minute, so a Quiet Guard server that hangs costs
  each worker one `MONITOR_TIMEOUT` a minute, whatever the number of queues.
- `Transport::pingAuto()`.

### Changed

- The core is required as `^0.4.0`. It carries no change of its own and is
  released at the same version, so the four packages of the family install
  as one.

### Requires

- A Quiet Guard server that reads the heartbeat body (quietguard.dev from its
  deployment of this change). An older server records the pings unarmed.

## v0.3.1

### Security

- The password of an HTTP Basic request travelled in clear. The request names
  its headers lower-cased with hyphens, so it sat under `php-auth-pw` while the
  `scrub` list writes `php_auth_pw`, and a name only matched as written. A
  hyphen counts as an underscore now, which also catches `x-api-key` under
  `api_key`.
- A password reset token travelled in the reported URL. `context.url` was set
  outside the scrubber, so the token and the signature in the query of
  Filament's reset link, and the token in the path of Laravel's own
  (`/reset-password/{token}`, the one Breeze, Fortify and Jetstream send),
  reached the server in clear. The URL goes through `Scrubber::scrubUrl()`
  now: the query values the `scrub` list names become `%5Bscrubbed%5D`, and so
  does every path segment made of forty letters or digits in a row.
  `signature`, the parameter that authorises a URL Laravel signed
  (`URL::signedRoute`), joins the default `scrub` list.
- A Livewire request was not opened. `components.*.snapshot` is a JSON string,
  and a scrubber that only descends into arrays handed it over whole, the
  state of the component and a password typed into it included. JSON written
  as a string is decoded, masked by key and written back now.
- A secret hardcoded into an array travelled in a snippet: in
  `$config['password'] = '...'` a `']` stood between the name and the `=`, and
  the line rule did not read it. Such a line is masked whole now, and so is a
  comparison with such an index.

### Fixed

- An empty `MONITOR_URL` silenced the client. v0.2.2 resolved an empty address
  to the hosted service and said so, but the two gates in front of the
  transport, the exception reporter and the log hook, still required a URL: an
  installation that left it empty, as the configuration invites, sent no
  exception and no log at all, and said nothing. Both gates ask for the key
  alone now.
- A snippet line that calls a static method (`Auth::user()`) is no longer
  masked whole. The first colon of `::` read as the start of a value, so such
  lines went, the line of the throw included. A hardcoded
  `'php-auth-pw' => '...'` line is masked, a `scrub` name with a hyphen or an
  underscore inside it being tried in both spellings.

### Changed

- The core is required as `^0.3.1`: this release calls
  `Scrubber::scrubUrl()` and relies on the masking rules that arrived with
  it, so it must never resolve with an older core.
- A machine that has a key and no `MONITOR_URL`, a staging box for instance,
  now reports to the hosted service. To keep it quiet, leave its
  `MONITOR_KEY` empty or set `MONITOR_ENABLED=false`.

## v0.3.0

### Added

- Stack traces start at the throw site and application frames carry a source
  snippet, the five lines on each side of the frame's line, so the dashboard
  shows the failing line in context. `MONITOR_CODE_SNIPPETS=false` sends file
  and line only. A line naming a key of the `scrub` list is masked whole.
  Dependencies never carry one. Requires `quiet-guard/monitor-php` with
  `SourceSnippet`.

- The release is detected when `MONITOR_RELEASE` is empty: from a `REVISION`
  file at the application root (what Capistrano, Deployer and most deploy
  scripts write), else from the checkout's own `.git/HEAD`, following a branch
  ref to its loose file or to `packed-refs`. The documentation used to say
  `MONITOR_RELEASE=${GIT_SHA}` and nothing about where that value comes from,
  so most installations sent no release at all and the *error to commit* link
  in the dashboard never lit up. `MONITOR_RELEASE` still wins when set;
  `MONITOR_RELEASE_AUTO=false` sends only the configured value, for a server
  whose checkout is not the branch that runs. The files are read once per
  process. The configuration page now carries one recipe per deployment shape
  (Forge, Envoyer, GitHub Actions, Docker, plain `git pull`).

## v0.2.2

### Fixed

- `MONITOR_URL` is optional again, for real this time. v0.2.1 put the hosted
  address in the **publishable config template**, and the installation page
  tells every reader to publish that file: the copy is frozen at the version
  they installed and wins over the package's defaults, so the default reached
  nobody who had followed the guide, and would not have reached them after
  `composer update` either. The address is resolved by the client now
  (`QuietGuard\Monitor\Config::HOSTED_URL`), applied wherever the value is
  read, and the template carries no address at all. `Transport` and the core
  `Reporter` each held their own copy of the trailing-`/api` stripping rule;
  both delegate to the one definition.
- The client asks only for a key. An empty `MONITOR_URL` used to silence it
  completely, which is not what an operator means by an empty value; use
  `MONITOR_ENABLED=false`, which says so.

### Added

- `monitor:restore --file=<blob>` decrypts a backup you already downloaded from
  the dashboard, instead of fetching its own copy. The Download button hands
  you the sealed `.enc` blob, because that is all the server holds, and until
  now nothing could open it. The id becomes optional; without it there is no
  published checksum to compare against, and the command says so. Your file is
  never deleted.

## v0.2.1

### Security

- Payloads are masked before they are queued. With `MONITOR_QUEUE` set, the
  masking ran on the wire only, so the raw exception and log payloads, with the
  email addresses, IBANs and card numbers the masking exists to catch, were
  serialised in clear into your `jobs` table and permanently into `failed_jobs`.

### Added

- `MONITOR_URL` defaults to `https://quietguard.dev`. Pointing at the hosted
  service now needs a key and nothing else; set the variable to reach your own
  instance.
- `monitor:backups` lists what the vault holds for this project: id, type, name,
  size and date. The endpoint has existed since the vault shipped and no command
  could reach it, so an id lost after an upload could only be found in the
  dashboard, while `monitor:restore` takes that id as its only argument.
- `monitor:backup --keep=N` deletes older backups of the same type after a
  successful upload. Nothing rotated anything before, so the nightly schedule the
  documentation recommends filled the plan allowance and then failed every night.

### Fixed

- A failure says what happened. `monitor:dependencies`, `monitor:backup` and
  `monitor:restore` print the status, the address and the server's own sentence.
  "Encryption is not configured for this team" used to answer four different
  situations, three of which that advice does not fix.
- An address that already ends in `/api/v1` is accepted, and whitespace around
  it is ignored. The client appends `/api/v1` itself, so the other reading of
  the setting answered 404 on every call.
- `MONITOR_QUEUE` names a queue CONNECTION, and an unknown one no longer stops
  reporting. `default`, `monitoring` or `high` are not keys under
  `queue.connections`, so the dispatch threw, the client swallowed it, and
  nothing was reported again. An unknown value now logs the valid connections
  and reports synchronously. This covers log batches too, where the failure was
  worse: the buffer is cleared before dispatch, so every flush lost its entries.
- One bad line no longer sinks a whole log batch. The server refuses an empty
  message and one over 8192 characters, and refuses the entire batch with it, so
  a single line could lose the two hundred it travelled with. Long messages are
  trimmed, empty ones substituted, and `MONITOR_LOGS_MAX_BATCH` is held to the
  server's own limit of 500, above which nothing was ever accepted.
- Exception messages, class names, file paths and release strings are trimmed to
  the server's limits instead of being refused and lost. A query exception
  carries its whole SQL in its message, and an exception from an anonymous class
  carries the file path inside its class name.
- `monitor:restore` compares the checksum the server publishes before it
  decrypts. A damaged download used to fail with the same message as a wrong
  passphrase, and those two do not get fixed the same way.
- `monitor:backup --database` no longer locks the database it is backing up:
  `mysqldump` runs with `--single-transaction`, `--no-tablespaces` so a
  least-privileged user is enough, and `--default-character-set=utf8mb4`. A
  failed sqlite copy is detected instead of producing an empty archive.
- A dump or an archive cut short by a full disk fails instead of being uploaded
  and reported as a success. A database backup needs about twice the dump size
  free in the temporary directory, once for the dump and once for the encrypted
  copy, and the message says so.
- `monitor:backup` checks its own options before the network call, so running it
  with none names the missing option rather than the encryption key.
- The queued jobs fail when the send failed, so the two attempts they declare
  are real. They completed silently whatever the server answered.

### Changed

- Requires `quiet-guard/monitor-php` `^0.2.1`.
