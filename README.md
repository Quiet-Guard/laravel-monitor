# LaravelMonitor — Client SDK

Report exceptions from any Laravel application to your [LaravelMonitor](../../) server.

## Requirements

- PHP 8.2+
- Laravel 11, 12 or 13

## Installation

While the package is developed inside the LaravelMonitor monorepo, install it
from a path (or VCS) repository. In the **application you want to monitor**, add
to its `composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/la-boite-a-code/laravelmonitor" }
    ]
}
```

Then require it:

```bash
composer require laboiteacode/laravel-monitor
```

The service provider and `Monitor` facade are auto-discovered.

## Configuration

Publish the config (optional) and set your environment variables:

```bash
php artisan vendor:publish --tag=monitor-config
```

```dotenv
MONITOR_ENABLED=true
MONITOR_URL=https://monitor.example.com
MONITOR_KEY=lm_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
# Optional
MONITOR_ENVIRONMENTS=production,staging
MONITOR_RELEASE=${GIT_SHA}
MONITOR_QUEUE=false        # or a queue connection name to report asynchronously
MONITOR_TIMEOUT=3

# Application logs (opt-in)
MONITOR_LOGS_ENABLED=true
MONITOR_LOG_LEVEL=warning  # minimum PSR-3 level to forward
MONITOR_LOGS_MAX_BATCH=200
```

`MONITOR_KEY` is the per-project API key generated in the LaravelMonitor
dashboard (shown only once at creation).

## How it works

The package registers an additive `reportable()` callback on Laravel's exception
handler, so every reported exception is forwarded to the monitor server **without
changing your existing logging**. Reporting is fail-safe: network or
configuration errors are swallowed and never affect the host application.

Set `MONITOR_QUEUE` to a queue connection to push reports onto a queue instead of
sending them synchronously during the request.

### Application logs

With `MONITOR_LOGS_ENABLED=true`, the package listens to Laravel's `MessageLogged`
event and forwards log messages at or above `MONITOR_LOG_LEVEL`. Entries are
**buffered during the request and flushed once in a single batch** when the
request (or command) terminates, so log forwarding adds at most one HTTP call.
Log entries that carry an exception are skipped — they are already covered by the
exception pipeline. Context values are scrubbed with the same rules as exceptions.

## Manual reporting

```php
use LaBoiteACode\LaravelMonitor\Facades\Monitor;

try {
    // ...
} catch (\Throwable $e) {
    Monitor::report($e);

    throw $e;
}
```

## Privacy

Sensitive request/context keys (passwords, tokens, cookies, authorization
headers, …) are masked before leaving your application. Extend the list via the
`scrub` config key.
