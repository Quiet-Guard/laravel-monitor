<?php

namespace QuietGuard\LaravelMonitor;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Jobs\SendExceptionToMonitor;
use QuietGuard\LaravelMonitor\Support\PayloadBuilder;
use Throwable;

class Monitor
{
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

            $payload = $this->builder->build($e);

            $queue = $this->config['queue'] ?? false;

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

    private function shouldReport(): bool
    {
        if (! ($this->config['enabled'] ?? false)) {
            return false;
        }

        if (blank($this->config['url'] ?? null) || blank($this->config['key'] ?? null)) {
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
