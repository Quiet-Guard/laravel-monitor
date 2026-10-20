<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Support\PayloadBuilder;
use RuntimeException;

/**
 * Send one test exception, now, and say whether the server took it.
 *
 * Synchronous on purpose, whatever MONITOR_QUEUE says: the point is to see
 * the answer in the terminal, not to wait for a worker. The environment list
 * does not stop it either, since checking the connection from a local machine
 * is what it is for; it only warns that real exceptions here would not go.
 */
class TestCommand extends Command
{
    protected $signature = 'monitor:test';

    protected $description = 'Send a test exception to Quiet Guard and show whether it arrived';

    public function handle(PayloadBuilder $builder, Transport $transport): int
    {
        $config = config('monitor');

        if (! ($config['enabled'] ?? false)) {
            $this->warn('Quiet Guard is disabled (MONITOR_ENABLED=false). Nothing sent.');

            return self::FAILURE;
        }

        if (blank($config['key'] ?? null)) {
            $this->error('MONITOR_KEY is empty: copy the project key from the dashboard into your .env.');

            return self::FAILURE;
        }

        $environments = $config['environments'] ?? [];

        if (! empty($environments) && ! in_array($this->laravel->environment(), $environments, true)) {
            $this->warn(sprintf(
                'This environment (%s) is not in MONITOR_ENVIRONMENTS (%s): real exceptions here are not reported. Sending the test anyway.',
                $this->laravel->environment(),
                implode(', ', $environments),
            ));
        }

        $exception = new RuntimeException('Quiet Guard test exception, sent by php artisan monitor:test. If you can read this in the dashboard, reporting works.');

        if (! $transport->send($builder->build($exception))) {
            $this->error('The test exception was not accepted: '.($transport->lastError() ?? 'no answer from the server.'));

            return self::FAILURE;
        }

        $this->info('Test exception sent. It appears in the project\'s Issues tab within a few seconds.');
        $this->line('Alert channels subscribed to new issues are notified once; running the command again groups into the same issue.');

        return self::SUCCESS;
    }
}
