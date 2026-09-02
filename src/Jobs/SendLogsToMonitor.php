<?php

namespace QuietGuard\LaravelMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use QuietGuard\LaravelMonitor\Http\Transport;

class SendLogsToMonitor implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function __construct(public array $logs) {}

    public function handle(Transport $transport): void
    {
        $transport->sendLogs($this->logs);
    }
}
