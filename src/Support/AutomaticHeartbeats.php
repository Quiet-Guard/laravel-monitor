<?php

namespace QuietGuard\LaravelMonitor\Support;

use Closure;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use QuietGuard\LaravelMonitor\Http\Transport;
use Throwable;

/**
 * The heartbeats the SDK declares by itself: the scheduler, and one per queue
 * a worker consumes.
 *
 * A worker that died raises no exception and a stopped scheduler logs
 * nothing: jobs pile up, mails do not leave, and the site still answers 200.
 * These two are the silences the rest of the SDK cannot see.
 *
 * Nothing is added to the application: no scheduled entry, no job. The worker
 * pings from its own loop (Looping, fired on every turn, even on an empty
 * queue) and schedule:run from its own start (CommandStarting). Nothing exists
 * on the server until the first ping, so an application with no scheduler or
 * no worker creates nothing and is never alerted. An alert can only mean that
 * something which worked once has stopped.
 *
 * Every entry point swallows what it raises: these run inside the customer's
 * worker loop and scheduler, and a monitoring SDK that stops either is worse
 * than no monitoring at all.
 */
final class AutomaticHeartbeats
{
    public const SCHEDULER_SLUG = 'scheduler';

    public const QUEUE_PERIOD = 1;

    public const QUEUE_GRACE = 9;

    /** At most one ping per queue in this window, across every worker sharing the cache. */
    public const QUEUE_PING_EVERY_SECONDS = 60;

    /** How long a worker sends no queue ping at all after one failed. */
    public const PAUSE_AFTER_FAILURE_SECONDS = 60;

    /** A schedule:run this close to the recorded start is that start, seen from another host. */
    public const SCHEDULER_SAME_START_SECONDS = 30;

    /** Added to the observed cadence, so one missed ping is not an alert. */
    public const SCHEDULER_MIN_GRACE = 4;

    /** The slowest cadence the server accepts as a period or a grace, one day. */
    public const CADENCE_MAX_MINUTES = 1440;

    /** Longer than the slowest cadence measured, so a daily cron still finds its previous start. */
    public const SCHEDULER_LAST_TTL_SECONDS = 604_800;

    /** @var list<string> */
    private const OFF = ['false', '0', 'off', 'no', 'none'];

    /** @var list<string> */
    private const ON = ['', 'true', '1', 'on', 'yes'];

    /**
     * When this process may next ask the shared cache about a queue, per
     * "connection:queue". A Looping fires before every job, and on Laravel's
     * default store a cache call is a database query: a busy worker asks at
     * most once a minute, whatever the other workers do.
     *
     * @var array<string, int>
     */
    private static array $nextCheckAt = [];

    /**
     * The shortest wait seen since this process last checked, in ms, per
     * "connection:queue".
     *
     * @var array<string, int>
     */
    private static array $lags = [];

    /**
     * Until when this process sends no queue ping, after one failed. Stopping
     * the turn is not enough: every queue it skipped is due at the next turn,
     * seconds away, so a server that hangs would still cost one timeout per
     * queue and per minute. Paused, it costs one a minute.
     */
    private static int $pausedUntil = 0;

    /** @var (Closure(): bool)|null */
    private static ?Closure $interactiveDetector = null;

    /**
     * The master switch, a key, and an environment both lists allow:
     * MONITOR_ENVIRONMENTS like every passive pipeline of the SDK, and the
     * heartbeats' own list, production by default, narrower on purpose since a
     * staging box switched off at night would alert every evening. Read at
     * every event, so a cached config cannot disagree with it.
     */
    public static function active(Application $app): bool
    {
        $config = (array) $app['config']->get('monitor', []);

        if (! ($config['enabled'] ?? false) || blank($config['key'] ?? null)) {
            return false;
        }

        $environment = $app->environment();

        foreach ([$config['environments'] ?? [], $config['heartbeats']['environments'] ?? ['production']] as $allowed) {
            $allowed = (array) $allowed;

            if ($allowed !== [] && ! in_array($environment, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a worker's queue is watched, from `monitor.heartbeats.queues`
     * read raw: env() hands over a real false for "false", and a cast to
     * string used to turn it into the default.
     *
     * true, null, "" or "true": every queue a worker consumes. false, "false",
     * "0" or "off": none. Anything else: a comma list of "connection:queue"
     * pairs; a name without a colon matches a connection (every queue of it)
     * or a queue (on any connection), so "default,emails" reads as written.
     */
    public static function watches(Application $app, string $connection, string $queue): bool
    {
        $setting = $app['config']->get('monitor.heartbeats.queues', true);

        if ($setting === null || is_bool($setting) || is_int($setting)) {
            return $setting === null || (bool) $setting;
        }

        if (is_string($setting)) {
            $normalised = strtolower(trim($setting));

            if (in_array($normalised, self::ON, true)) {
                return true;
            }

            // "false" arrives as a real false from env(); "0" or "off" read
            // as a list that names no connection, and so watch nothing.
            $setting = explode(',', $setting);
        }

        foreach ((array) $setting as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            [$watchedConnection, $watchedQueue] = array_pad(explode(':', trim($entry), 2), 2, null);
            $watchedConnection = trim($watchedConnection);

            if ($watchedQueue === null
                ? in_array($watchedConnection, [$connection, $queue], true)
                : $watchedConnection === $connection && trim($watchedQueue) === $queue) {
                return true;
            }
        }

        return false;
    }

    public static function slugFor(string $connection, string $queue): string
    {
        return Str::slug("queue {$connection} {$queue}");
    }

    public static function queueThrottleKey(Application $app, string $connection, string $queue): string
    {
        return 'monitor:hb:'.self::keyScope($app).":queue:{$connection}:{$queue}";
    }

    public static function schedulerLastKey(Application $app): string
    {
        return 'monitor:hb:'.self::keyScope($app).':scheduler:last';
    }

    /**
     * Eight characters of a hash of the project key: two applications on one
     * Redis with the same prefix would otherwise read each other's scheduler
     * start and take each other's queue turn. Never the key itself, which is
     * a credential and has no business in a cache key.
     */
    private static function keyScope(Application $app): string
    {
        return substr(sha1((string) $app['config']->get('monitor.key')), 0, 8);
    }

    /**
     * One turn of a queue:work loop, Horizon included. `queue:listen` and
     * `queue:work --once` never loop, so they never reach here.
     *
     * Returns nothing, ever: the Worker dispatches this event with until() and
     * stops the daemon on a false answer.
     */
    public static function onLooping(Application $app, Looping $event): void
    {
        try {
            if (Carbon::now()->getTimestamp() < self::$pausedUntil || ! self::active($app) || ! is_string($event->queue)) {
                return;
            }

            $connection = (string) $event->connectionName;
            $grace = self::queueGrace($event);
        } catch (Throwable $e) {
            self::warn($app, 'queue', $e);

            return;
        }

        foreach (array_unique(array_filter(array_map('trim', explode(',', $event->queue)))) as $queue) {
            try {
                if (self::watches($app, $connection, $queue) && ! self::beat($app, $connection, $queue, $grace)) {
                    self::pauseQueuePings();

                    return;
                }
            } catch (Throwable $e) {
                self::pauseQueuePings();
                self::warn($app, 'queue', $e);

                return;
            }
        }
    }

    /**
     * A worker took a job: how long it waited since it was queued. createdAt
     * is written to the second by Laravel 12 and later; without it (Laravel
     * 11) nothing is measured, which the ping then omits rather than zeroes.
     */
    public static function onJobProcessing(Application $app, JobProcessing $event): void
    {
        try {
            if (! self::active($app)) {
                return;
            }

            $job = $event->job;
            $createdAt = $job->payload()['createdAt'] ?? null;

            // Nothing to measure: Laravel 11 does not write it, and there is
            // no reason to ask the job anything else.
            if (! is_int($createdAt)) {
                return;
            }

            // A retry waited on its backoff, not on a worker.
            if ($job->attempts() > 1) {
                return;
            }

            $key = $event->connectionName.':'.self::queueName((string) $job->getQueue());
            $lag = max(0, (Carbon::now()->getTimestamp() - $createdAt) * 1000);

            self::$lags[$key] = min(self::$lags[$key] ?? $lag, $lag);
        } catch (Throwable) {
            // A measure that cannot be taken is a measure the ping omits.
        }
    }

    /**
     * schedule:run started, maintenance mode included, and in each subprocess
     * schedule:work spawns. The first start only records the time; from the
     * second on, the gap between two starts is the cadence the server expects,
     * so a cron every five minutes or every hour is not late between runs.
     * Needs a cache that outlives the process: on the `array` store nothing is
     * ever remembered and nothing is sent.
     */
    public static function onCommandStarting(Application $app, CommandStarting $event): void
    {
        try {
            if ($event->command !== 'schedule:run' || ! self::active($app) || ! self::schedulerWatched($app)) {
                return;
            }

            // Typed in a terminal, not started by cron: two runs a minute apart
            // before an hourly cron would arm a one-minute period for good,
            // and the server never re-arms. Neither recorded nor pinged.
            if (self::runByHand()) {
                return;
            }

            $cache = $app->make(Cache::class);
            $now = Carbon::now()->getTimestamp();
            // Redis hands a stored number back as a numeric string, the array
            // and file stores as the integer: read both, refuse anything else.
            $previous = filter_var($cache->get(self::schedulerLastKey($app)), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);

            // Several hosts running the same cron on one cache start within
            // seconds of each other, on clocks that may disagree by as much:
            // the second one would read the first one's start and arm a
            // one-minute period on a five-minute cron. It is the same start,
            // neither recorded again nor pinged.
            if ($previous !== null && abs($now - $previous) < self::SCHEDULER_SAME_START_SECONDS) {
                return;
            }

            $cache->put(self::schedulerLastKey($app), $now, self::SCHEDULER_LAST_TTL_SECONDS);

            if ($previous === null) {
                return;
            }

            $gap = (int) round(max(0, $now - $previous) / 60);
            $period = min(self::CADENCE_MAX_MINUTES, max(1, $gap));

            $app->make(Transport::class)->pingAuto(self::SCHEDULER_SLUG, [
                'kind' => 'scheduler',
                'period' => $period,
                // A full cadence plus a margin: one missed ping (a flushed
                // cache, a timeout, a 429) must not be an alert.
                'grace' => min(self::CADENCE_MAX_MINUTES, $period + self::SCHEDULER_MIN_GRACE),
            ]);
        } catch (Throwable $e) {
            self::warn($app, 'scheduler', $e);
        }
    }

    /**
     * Forget this process's throttle and measures. For tests, where one
     * process plays several workers.
     */
    public static function flushState(): void
    {
        self::$nextCheckAt = [];
        self::$lags = [];
        self::$pausedUntil = 0;
    }

    /**
     * Replace how a schedule:run typed in a terminal is told from one cron
     * started; null restores the STDIN check. For tests, whose own STDIN is a
     * terminal whenever somebody runs the suite by hand.
     *
     * @param  (Closure(): bool)|null  $detector
     */
    public static function detectInteractiveUsing(?Closure $detector): void
    {
        self::$interactiveDetector = $detector;
    }

    /**
     * False only when a ping was sent and failed; skipping a queue this turn,
     * or leaving it to another worker, is not a failure.
     */
    private static function beat(Application $app, string $connection, string $queue, int $grace): bool
    {
        $key = "{$connection}:{$queue}";
        $now = Carbon::now()->getTimestamp();

        if ((self::$nextCheckAt[$key] ?? 0) > $now) {
            return true;
        }

        // Set before anything that can fail: a broken cache is asked, and
        // logged, once a minute rather than once per job.
        self::$nextCheckAt[$key] = $now + self::QUEUE_PING_EVERY_SECONDS;

        // Consumed at every check, won or lost: a worker that lets another one
        // ping keeps no stale measure to send an hour later.
        $lag = self::$lags[$key] ?? null;
        unset(self::$lags[$key]);

        // One ping per queue per minute across the workers sharing this cache.
        // Without a shared cache each worker pings on its own, which the
        // server takes as the same heartbeat.
        if (! $app->make(Cache::class)->add(self::queueThrottleKey($app, $connection, $queue), $now, self::QUEUE_PING_EVERY_SECONDS)) {
            return true;
        }

        $meta = [
            'kind' => 'queue',
            'period' => self::QUEUE_PERIOD,
            'grace' => $grace,
            'queue' => "{$connection}/{$queue}",
        ];

        if ($lag !== null) {
            $meta['lag_ms'] = $lag;
        }

        // Transport::pingAuto() never throws and sends right now, whatever
        // MONITOR_QUEUE says: a dead queue must not carry its own heartbeat.
        return $app->make(Transport::class)->pingAuto(self::slugFor($connection, $queue), $meta);
    }

    /**
     * A ping failed or threw: every queue of this process waits a minute. The
     * other workers keep their own clock, each paying one timeout a minute.
     */
    private static function pauseQueuePings(): void
    {
        self::$pausedUntil = Carbon::now()->getTimestamp() + self::PAUSE_AFTER_FAILURE_SECONDS;
    }

    /**
     * Looping fires between two jobs only, so one job of fifteen minutes is a
     * worker silent for fifteen minutes with nothing wrong: the grace covers
     * the worker's own timeout, plus a minute. Laravel 13 puts the worker's
     * options on the event; older versions, and a timeout of 0 (none), keep
     * the default.
     */
    private static function queueGrace(Looping $event): int
    {
        $options = $event->workerOptions ?? null;
        $timeout = $options instanceof WorkerOptions ? $options->timeout : null;

        if (! is_numeric($timeout) || (int) $timeout <= 0) {
            return self::QUEUE_GRACE;
        }

        return min(self::CADENCE_MAX_MINUTES, max(self::QUEUE_GRACE, (int) ceil((int) $timeout / 60) + 1));
    }

    /**
     * Cron, supervisor and the subprocesses of schedule:work all run with no
     * terminal on STDIN; a person at a shell has one. Not
     * InputInterface::isInteractive(), which only says whether --no-interaction
     * was passed.
     */
    private static function runByHand(): bool
    {
        if (self::$interactiveDetector !== null) {
            return (bool) (self::$interactiveDetector)();
        }

        return defined('STDIN') && is_resource(STDIN) && stream_isatty(STDIN);
    }

    private static function schedulerWatched(Application $app): bool
    {
        $setting = $app['config']->get('monitor.heartbeats.scheduler', true);

        if (is_string($setting)) {
            return ! in_array(strtolower(trim($setting)), self::OFF, true);
        }

        return $setting === null || (bool) $setting;
    }

    /**
     * The name a worker loops on. An SQS job reports its queue by URL; the
     * worker names it plainly.
     */
    private static function queueName(string $queue): string
    {
        if (filter_var($queue, FILTER_VALIDATE_URL) === false) {
            return $queue;
        }

        return basename((string) parse_url($queue, PHP_URL_PATH));
    }

    private static function warn(Application $app, string $hook, Throwable $e): void
    {
        try {
            $app->make('log')->warning('Quiet Guard: automatic heartbeat failed', [
                'hook' => $hook,
                'error' => $e->getMessage(),
                Transport::INTERNAL => true,
            ]);
        } catch (Throwable) {
            // Not even a log line is worth an exception in a worker.
        }
    }
}
