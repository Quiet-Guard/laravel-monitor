<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use QuietGuard\LaravelMonitor\Support\PerformanceFlusher;
use QuietGuard\LaravelMonitor\Support\PerformanceRecorder;

/**
 * Sends the performance windows that are due. The SDK schedules it every
 * minute, in the background, when MONITOR_PERFORMANCE is on: nothing to add
 * to your scheduler.
 *
 * Every application's scheduler fires at the first second of the minute, so
 * the command first waits for this instance's own second (5 to 45), which
 * spreads the arrivals, then works for three seconds at most.
 */
class PerformanceFlushCommand extends Command
{
    protected $signature = 'monitor:performance:flush';

    protected $description = 'Send the Quiet Guard performance measurements that are due';

    protected $hidden = true;

    public function handle(): int
    {
        if (! PerformanceRecorder::active($this->laravel)) {
            return self::SUCCESS;
        }

        $flusher = $this->laravel->make(PerformanceFlusher::class);
        $second = (int) Carbon::now()->format('s');

        if ($second < $flusher->offset()) {
            Sleep::for($flusher->offset() - $second)->seconds();
        }

        $flusher->flushDue(3.0);

        return self::SUCCESS;
    }
}
