<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * One reading of MONITOR_QUEUE, for the two paths that queue.
 *
 * It is a queue CONNECTION, not a queue name, and the variable reads like the
 * opposite: `default`, `monitoring`, `high` are the values one writes
 * naturally, and none is a key under `queue.connections`. Queue::connection()
 * then throws, and both callers sit inside a catch that swallows it.
 *
 * The two consequences differed, and the log one was worse. An exception report
 * was simply not sent. A log BATCH was already cleared from the buffer before
 * the dispatch, so up to five hundred entries were gone, every flush, for ever,
 * with nothing said anywhere.
 *
 * Written here rather than twice: the exception path had this check and the log
 * path did not, which is exactly how one half of a fix goes missing.
 */
final class QueueConnection
{
    /**
     * @return string|bool a connection name, true for the default connection, false for synchronous
     */
    public static function resolve(Application $app, mixed $queue): string|bool
    {
        if (! is_string($queue)) {
            return $queue !== false;
        }

        $connections = array_keys((array) $app['config']->get('queue.connections', []));

        if (in_array($queue, $connections, true)) {
            return $queue;
        }

        $app['log']->warning(
            "Quiet Guard: MONITOR_QUEUE names no queue CONNECTION ({$queue}); reporting synchronously instead.",
            ['available' => $connections, 'monitor_internal' => true],
        );

        return false;
    }
}
