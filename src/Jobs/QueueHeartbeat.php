<?php

namespace QuietGuard\LaravelMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Support\AutomaticHeartbeats;
use Throwable;

/**
 * The probe a watched queue carries. Taken by a worker, it pings the queue's
 * heartbeat with how long it waited: a dead worker never takes it, so the
 * heartbeat goes silent and the server alerts.
 *
 * It pings synchronously from the worker and never through MONITOR_QUEUE: a
 * dead queue would otherwise hide its own death.
 *
 * Its own properties are named watched*, because Queueable already declares
 * $connection and $queue, which onConnection() / onQueue() fill.
 */
final class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    /** A failed probe is not retried: the next one comes within a minute. */
    public int $tries = 1;

    public function __construct(
        public readonly string $watchedConnection,
        public readonly string $watchedQueue,
        public readonly float $dispatchedAt,
    ) {}

    public function handle(Application $app): void
    {
        try {
            $app->make(Cache::class)->forget(
                AutomaticHeartbeats::pendingKey($this->watchedConnection, $this->watchedQueue),
            );
        } catch (Throwable) {
            // The mark expires on its own; the ping below matters more.
        }

        if (! AutomaticHeartbeats::active($app)) {
            return;
        }

        // Transport::pingAuto() never throws and sends right now, whatever
        // MONITOR_QUEUE says.
        $app->make(Transport::class)->pingAuto(
            AutomaticHeartbeats::slugFor($this->watchedConnection, $this->watchedQueue),
            [
                'kind' => 'queue',
                'period' => AutomaticHeartbeats::QUEUE_PERIOD,
                'grace' => AutomaticHeartbeats::QUEUE_GRACE,
                'queue' => "{$this->watchedConnection}/{$this->watchedQueue}",
                'lag_ms' => max(0, (int) round((microtime(true) - $this->dispatchedAt) * 1000)),
            ],
        );
    }
}
