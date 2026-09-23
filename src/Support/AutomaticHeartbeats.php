<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Jobs\QueueHeartbeat;
use Throwable;

/**
 * The heartbeats the SDK declares by itself: the scheduler, and one per
 * watched queue.
 *
 * A worker that died raises no exception and a stopped scheduler logs
 * nothing: jobs pile up, mails do not leave, and the site still answers 200.
 * These two are the silences the rest of the SDK cannot see.
 *
 * Nothing exists on the server until the first ping, so an application with no
 * scheduler or no worker creates nothing and is never alerted. An alert can
 * only mean that something which worked once has stopped.
 */
final class AutomaticHeartbeats
{
    public const SCHEDULER_SLUG = 'scheduler';

    public const SCHEDULER_PERIOD = 1;

    public const SCHEDULER_GRACE = 4;

    public const QUEUE_PERIOD = 1;

    /** Longer than the scheduler's, so a stopped scheduler is the one that alerts. */
    public const QUEUE_GRACE = 9;

    /** At most one pending probe job per queue in this window. */
    public const PENDING_TTL_SECONDS = 900;

    /** @var list<string> */
    private const DRIVERS_WITHOUT_WORKER = ['sync', 'null', 'deferred', 'background'];

    public static function register(Schedule $schedule, Application $app): void
    {
        try {
            if (! self::active($app)) {
                return;
            }

            if ((bool) $app['config']->get('monitor.heartbeats.scheduler', true)) {
                $schedule->call(fn () => self::pingScheduler($app))
                    ->everyMinute()
                    ->name('monitor:heartbeat:scheduler')
                    ->onOneServer();
            }

            foreach (self::queues($app) as ['connection' => $connection, 'queue' => $queue]) {
                $schedule->call(fn () => self::dispatchProbe($app, $connection, $queue))
                    ->everyMinute()
                    ->name("monitor:heartbeat:queue:{$connection}:{$queue}")
                    ->onOneServer();
            }
        } catch (Throwable) {
            // Registration runs whenever the application resolves its
            // Schedule, schedule:list included: it must never break it.
        }
    }

    /**
     * The master switch, a key, and an environment the heartbeats are meant
     * for. One definition, read at registration and again at run time, so
     * config:cache cannot leave a ping that registration would have refused.
     */
    public static function active(Application $app): bool
    {
        $config = (array) $app['config']->get('monitor', []);

        if (! ($config['enabled'] ?? false) || blank($config['key'] ?? null)) {
            return false;
        }

        $environments = (array) ($config['heartbeats']['environments'] ?? ['production']);

        return $environments === [] || in_array($app->environment(), $environments, true);
    }

    /**
     * @return list<array{connection: string, queue: string}>
     */
    public static function queues(Application $app): array
    {
        $config = $app['config'];
        $setting = trim((string) $config->get('monitor.heartbeats.queues', ''));

        if (strtolower($setting) === 'false') {
            return [];
        }

        $pairs = $setting === ''
            ? [[(string) $config->get('queue.default'), null]]
            : array_map(fn (string $pair): array => array_pad(explode(':', trim($pair), 2), 2, null), explode(',', $setting));

        $watched = [];

        foreach ($pairs as [$connection, $queue]) {
            $connection = trim((string) $connection);
            $driver = $config->get("queue.connections.{$connection}.driver");

            if ($connection === '' || $driver === null || in_array($driver, self::DRIVERS_WITHOUT_WORKER, true)) {
                continue;
            }

            $queue = trim((string) ($queue ?? $config->get("queue.connections.{$connection}.queue", 'default')));

            $watched[] = ['connection' => $connection, 'queue' => $queue === '' ? 'default' : $queue];
        }

        return $watched;
    }

    public static function slugFor(string $connection, string $queue): string
    {
        return Str::slug("queue {$connection} {$queue}");
    }

    public static function pendingKey(string $connection, string $queue): string
    {
        return "monitor:qhb:{$connection}:{$queue}";
    }

    private static function pingScheduler(Application $app): void
    {
        try {
            if (! self::active($app)) {
                return;
            }

            // Transport never throws; the guard is for resolving it.
            $app->make(Transport::class)->pingAuto(self::SCHEDULER_SLUG, [
                'kind' => 'scheduler',
                'period' => self::SCHEDULER_PERIOD,
                'grace' => self::SCHEDULER_GRACE,
            ]);
        } catch (Throwable) {
            // A heartbeat that cannot be sent is a heartbeat that goes silent,
            // which the server reports. Nothing to raise here.
        }
    }

    private static function dispatchProbe(Application $app, string $connection, string $queue): void
    {
        if (! self::active($app)) {
            return;
        }

        $claimed = false;

        try {
            $cache = $app->make(Cache::class);

            // One pending probe per queue: a dead queue receives one job per
            // window instead of one a minute, and a worker coming back does
            // not answer with a burst of pings. Needs a cache the scheduler
            // and the workers share, which is any single host or one Redis;
            // on an `array` store it degrades to one job a minute, no worse.
            if (! $cache->add(self::pendingKey($connection, $queue), time(), self::PENDING_TTL_SECONDS)) {
                return;
            }

            $claimed = true;

            $app->make(Dispatcher::class)->dispatch(
                (new QueueHeartbeat($connection, $queue, microtime(true)))
                    ->onConnection($connection)
                    ->onQueue($queue),
            );
        } catch (Throwable $e) {
            // Nothing was queued, so nothing will release the mark: release it
            // here, or a connection that recovers would wait out the window.
            if ($claimed) {
                try {
                    $app->make(Cache::class)->forget(self::pendingKey($connection, $queue));
                } catch (Throwable) {
                    // The mark expires on its own.
                }
            }

            // A broken queue connection must not break the customer's scheduler.
            try {
                $app['log']->warning('Quiet Guard: could not dispatch the queue heartbeat', [
                    'connection' => $connection,
                    'queue' => $queue,
                    'error' => $e->getMessage(),
                    Transport::INTERNAL => true,
                ]);
            } catch (Throwable) {
                // Not even a log line is worth an exception in the scheduler.
            }
        }
    }
}
