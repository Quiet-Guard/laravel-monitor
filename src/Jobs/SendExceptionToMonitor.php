<?php

namespace QuietGuard\LaravelMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use QuietGuard\LaravelMonitor\Http\Transport;

class SendExceptionToMonitor implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(Transport $transport): void
    {
        $transport->send($this->payload);
    }
}
