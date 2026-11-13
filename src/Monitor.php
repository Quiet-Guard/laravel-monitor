<?php

namespace QuietGuard\LaravelMonitor;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Jobs\SendExceptionToMonitor;
use QuietGuard\LaravelMonitor\Support\PayloadBuilder;
use QuietGuard\LaravelMonitor\Support\QueueConnection;
use Throwable;

class Monitor
{
    /** Seconds during which the same exception, from the same place, is sent once. */
    public const DEDUPE_SECONDS = 60;

    /** How long the count of the occurrences skipped waits for the next report. */
    private const SKIPPED_TTL_SECONDS = 86_400;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Application $app,
        private readonly PayloadBuilder $builder,
        private readonly Transport $transport,
        private readonly array $config,
    ) {}

    /**
     * Report a throwable to the monitor server. Never throws.
     */
    public function report(Throwable $e): void
    {
        try {
            if (! $this->shouldReport()) {
                return;
            }

            $skipped = $this->skippedSinceLastReport($e);

            if ($skipped === null) {
                return;
            }

            // Masked BEFORE anything is queued: otherwise the raw payload is
            // serialised into the client's jobs table, and for good into
            // failed_jobs. Transport does it again when it sends, which costs
            // nothing on a payload already masked and keeps its guarantee.
            $payload = $this->transport->redact($this->builder->build($e));

            if ($skipped > 0) {
                $payload['skipped'] = $skipped;
            }

            $queue = QueueConnection::resolve($this->app, $this->config['queue'] ?? false);

            if ($queue !== false) {
                $job = new SendExceptionToMonitor($payload);

                if (is_string($queue)) {
                    $job->onConnection($queue);
                }

                // Dispatcher::class, not the 'bus' alias: no such alias exists,
                // so make('bus') raised a BindingResolutionException that report()'s
                // own catch swallowed, and a queued report was silently dropped.
                // LogCollector already resolves it this way.
                $this->app->make(Dispatcher::class)->dispatch($job);

                return;
            }

            $this->transport->send($payload);
        } catch (Throwable) {
            // Reporting must never break the host application.
        }
    }

    /**
     * The same exception, from the same place, is sent once per window: an
     * exception thrown in a loop of ten thousand would otherwise be ten
     * thousand reports, and as many off the quota. The ones held back are
     * counted, and the next report of that exception carries the count, which
     * the server adds to the issue's occurrences.
     *
     * Null means hold this one back; otherwise the number of occurrences held
     * back since the last report. The window lives in the application's cache,
     * so it holds across requests and workers; a cache that fails reads as
     * "send it", losing a report being the worse of the two failures.
     */
    private function skippedSinceLastReport(Throwable $e): ?int
    {
        $window = (int) ($this->config['dedupe_seconds'] ?? self::DEDUPE_SECONDS);

        if ($window <= 0) {
            return 0;
        }

        try {
            $cache = $this->app->make(Cache::class);
            $key = 'quiet-guard:dedupe:'.sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());

            if ($cache->add($key, true, $window)) {
                return (int) $cache->pull($key.':skipped', 0);
            }

            // add() first: the database store's increment() does nothing on a
            // key that does not exist yet.
            $cache->add($key.':skipped', 0, self::SKIPPED_TTL_SECONDS);
            $cache->increment($key.':skipped');

            return null;
        } catch (Throwable) {
            return 0;
        }
    }

    private function shouldReport(): bool
    {
        if (! ($this->config['enabled'] ?? false)) {
            return false;
        }

        // The key alone: an empty MONITOR_URL is a configured client that
        // takes the hosted address (Config::normaliseUrl), not an absent one.
        if (blank($this->config['key'] ?? null)) {
            return false;
        }

        $environments = $this->config['environments'] ?? [];

        if (! empty($environments) && ! in_array($this->app->environment(), $environments, true)) {
            return false;
        }

        if ($this->onIgnoredPath()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the request being served is one whose exceptions are not reported.
     *
     * Anything unexpected here reads as "no match" and reporting carries on:
     * losing a report is the worse of the two failures. A console command
     * carries a dummy request whose path is "/", which matches nothing.
     */
    private function onIgnoredPath(): bool
    {
        try {
            $patterns = $this->config['ignore_paths'] ?? [];

            if (! is_array($patterns)) {
                return false;
            }

            $patterns = array_values(array_filter($patterns, 'is_string'));

            if ($patterns === [] || ! $this->app->bound('request')) {
                return false;
            }

            $request = $this->app->make('request');

            return $request instanceof Request && $request->is(...$patterns);
        } catch (Throwable) {
            return false;
        }
    }
}
