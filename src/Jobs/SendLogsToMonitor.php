<?php

namespace QuietGuard\LaravelMonitor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use QuietGuard\LaravelMonitor\Http\Transport;
use RuntimeException;

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
        // Lever quand l'envoi a échoué, sinon $tries ne veut rien dire : send()
        // n'a jamais levé, il rend false, donc handle() se terminait toujours
        // normalement et le job était toujours marqué comme réussi. Un 500 du
        // serveur, un délai dépassé, une connexion coupée : rapport perdu, pas
        // de seconde tentative, rien dans failed_jobs, et la documentation
        // promettait deux essais.
        if (! $transport->sendLogs($this->logs)) {
            throw new RuntimeException(
                'Quiet Guard: '.($transport->lastError() ?? 'the report could not be sent.')
            );
        }
    }
}
