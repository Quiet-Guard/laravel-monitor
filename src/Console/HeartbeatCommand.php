<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use QuietGuard\LaravelMonitor\Http\Transport;

/**
 * Ping a scheduled-task heartbeat. Chain it after the task you monitor:
 *
 *   $schedule->command('reports:send')->daily()
 *       ->onSuccess(fn () => Artisan::call('monitor:heartbeat', ['slug' => 'reports-send']));
 *
 * or simply schedule it right after. First ping auto-registers the heartbeat
 * on the server; arm it (expected period) from the dashboard to enable alerts.
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'monitor:heartbeat {slug : Heartbeat identifier (letters, digits, dashes)}';

    protected $description = 'Ping a Quiet Guard heartbeat so the server knows this scheduled task ran';

    public function handle(Transport $transport): int
    {
        $config = config('monitor');

        if (! ($config['enabled'] ?? false)) {
            $this->warn('Quiet Guard is disabled (MONITOR_ENABLED=false). Nothing sent.');

            return self::SUCCESS;
        }

        $slug = (string) $this->argument('slug');

        if ($transport->ping($slug)) {
            $this->info("Heartbeat '{$slug}' pinged.");

            return self::SUCCESS;
        }

        $this->error("Heartbeat '{$slug}' could not be pinged.");

        return self::FAILURE;
    }
}
