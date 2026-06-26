<?php

namespace LaBoiteACode\LaravelMonitor;

use Illuminate\Contracts\Foundation\Application;
use LaBoiteACode\LaravelMonitor\Http\Transport;
use LaBoiteACode\LaravelMonitor\Jobs\SendExceptionToMonitor;
use LaBoiteACode\LaravelMonitor\Support\PayloadBuilder;
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

                $this->app->make('bus')->dispatch($job);

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

        return true;
    }
}
