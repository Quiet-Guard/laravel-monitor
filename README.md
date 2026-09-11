# Quiet Guard: Client SDK

Report exceptions, application logs, dependencies and heartbeats from any
Laravel application to your
Quiet Guard server.
Built on the framework-agnostic core `quiet-guard/monitor-php`, the same
engine that powers the Symfony bundle and the WordPress plugin.

## Requirements

- PHP 8.2+ with `ext-curl` and `ext-sodium` (required by the core) and
  `ext-phar` (folder backups)
- Laravel 11, 12 or 13

## Installation

```bash
composer require quiet-guard/laravel-monitor
```

The service provider and `Monitor` facade are auto-discovered.

## Configuration

Publish the config (optional) and set your environment variables:

```bash
php artisan vendor:publish --tag=monitor-config
```

```dotenv
MONITOR_ENABLED=true
MONITOR_KEY=lm_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
# Optional
MONITOR_URL=https://monitor.example.com   # defaults to the hosted service, https://quietguard.dev
MONITOR_ENVIRONMENTS=production,staging
MONITOR_RELEASE=            # empty: detected from a REVISION file or .git/HEAD (MONITOR_RELEASE_AUTO=false to disable)
MONITOR_QUEUE=false        # false: synchronous. true: the app's default queue connection. A queue CONNECTION name: that connection.
MONITOR_TIMEOUT=3
MONITOR_TRACE_LIMIT=0      # 0 = full stack trace (default); a positive value trims; the throw site counts as one frame
MONITOR_CODE_SNIPPETS=true # a few lines of your own code around each application frame; false sends file and line only

# Application logs (opt-in)
MONITOR_LOGS_ENABLED=true
MONITOR_LOG_LEVEL=warning  # minimum PSR-3 level to forward
MONITOR_LOGS_MAX_BATCH=200 # clamped to 500, the server's own batch limit
```

`MONITOR_KEY` is the per-project API key generated in the Quiet Guard
dashboard (shown only once at creation). `MONITOR_URL` already points at the
hosted service, so set it only to reach a self-hosted instance; a value that
already ends in `/api` or `/api/v1` is accepted and normalised, the SDK
appending `/api/v1/...` itself.

## How it works

The package registers an additive `reportable()` callback on Laravel's exception
handler, so every reported exception is forwarded to the monitor server **without
changing your existing logging**. Reporting is fail-safe: network or
configuration errors are swallowed and never affect the host application.

`MONITOR_QUEUE` controls how a report leaves: `false` (the default) sends it
synchronously during the request, `true` pushes it onto the application's default
queue connection, and a connection name pushes it onto that connection instead.
A queue worker must be running for a queued report to actually leave.

It names a queue **connection**, a key of `queue.connections`, not a queue:
`default`, `monitoring` or `high` name none. An unknown value is refused for
exception reports, with a warning naming the connections that do exist and a
synchronous send instead. Payloads are masked before they are queued, so
personal data never sits in clear in your `jobs` / `failed_jobs` tables, and a
job whose send failed throws, so its second attempt is real.

A refusal the server explains (a `402` during dunning, a `403` on a plan gate,
a `422` on a field) is written to your own log as a warning naming the status,
the address and what the server said.

By default every exception is reported. Set `ignore_paths` in `config/monitor.php`
to a list of request paths whose exceptions are never reported, written in the
syntax `Illuminate\Http\Request::is()` accepts (for example `api/v1/*`); empty by
default. It only gates exceptions, logs are unaffected. Useful when the
application hosts a monitoring endpoint of its own: the exceptions raised while
serving that endpoint should not be reported back to it.

### Application logs

With `MONITOR_LOGS_ENABLED=true`, the package listens to Laravel's `MessageLogged`
event and forwards log messages at or above `MONITOR_LOG_LEVEL`. Entries are
**buffered during the request and flushed once in a single batch** when the
request (or command) terminates, so log forwarding adds at most one HTTP call.
Log entries that carry an exception are skipped, they are already covered by the
exception pipeline. Context values are scrubbed with the same rules as exceptions,
and the `MONITOR_ENVIRONMENTS` allowlist applies to logs exactly like exceptions.

Logs require the **logs** feature on your plan, and forwarded entries count
toward your team's monthly event quota alongside exceptions.

## Dependency vulnerability scanning

Report the app's installed packages (from `composer.lock`) so the server can scan
them for known vulnerabilities. Run this as part of your deploy:

```bash
php artisan monitor:dependencies
```

Pass `--path` to point at a specific `composer.lock`. Nothing is sent when
`MONITOR_ENABLED=false`.

## Heartbeats (scheduled-task monitoring)

Tell the server a scheduled task ran, so it can alert you when the task goes
silent. The preferred wiring is the scheduler macro, which pings **only when
the task succeeded**:

```php
Schedule::command('reports:send')->daily()->monitorHeartbeat('reports-send');
```

You can also ping manually, from a cron line for example:

```bash
php artisan monitor:heartbeat reports-send
```

The first ping auto-registers the heartbeat on the server; arm it there by
setting its expected period. The macro honours the `MONITOR_ENABLED` master
switch and the `MONITOR_ENVIRONMENTS` allowlist, so a staging scheduler never
pings a production heartbeat.

## Encrypted backups

With the backups feature enabled on your plan, upload zero-knowledge encrypted
backups to the vault. The archive is encrypted locally with a random key sealed
to your team's public key; the server only ever stores opaque ciphertext.

```bash
php artisan monitor:backup --database            # database dump
php artisan monitor:backup --path=storage/app    # folder archive
php artisan monitor:backup --database --keep=7   # rotate: keep the 7 newest of this type
php artisan monitor:backups                      # list what the vault holds, with each id
php artisan monitor:restore {id} --output=/tmp   # decrypts locally with the team passphrase
```

Nothing on the server deletes a backup for you, so pass `--keep` on a scheduled
one. `monitor:restore` checks the downloaded blob against the sha256 the server
publishes before decrypting, so a damaged download is no longer reported the
same way as a wrong passphrase.

## Manual reporting

```php
use QuietGuard\LaravelMonitor\Facades\Monitor;

try {
    // ...
} catch (\Throwable $e) {
    Monitor::report($e);

    throw $e;
}
```

## Privacy

Sensitive request/context keys (passwords, tokens, cookies, authorization
headers...) are masked before leaving your application. Extend the list via the
`scrub` config key. Stack-trace frame arguments are never sent.

## Documentation

Full documentation is served by your Quiet Guard server under `/docs`
(for example `https://monitor.example.com/docs`), including installation,
alerting, heartbeats, uptime monitoring and encrypted backups guides.

## License

MIT. See [LICENSE](LICENSE).
