<?php

namespace QuietGuard\LaravelMonitor;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Jobs\SendExceptionToMonitor;
use QuietGuard\LaravelMonitor\Support\PayloadBuilder;
use Throwable;

class Monitor
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Application $app,
        private readonly PayloadBuilder $builder,
        private readonly Transport $transport,
        private readonly array $config,
    ) {}

    /**
     * Report a throwable to the monitor server. Never throws.
     */
    public function report(Throwable $e): void
    {
        try {
            if (! $this->shouldReport()) {
                return;
            }

            // Masqué AVANT toute mise en file : sinon la charge brute est
            // sérialisée dans la table jobs du client, et définitivement dans
            // failed_jobs. Transport le refait au moment de l'envoi, ce qui ne
            // coûte rien sur une charge déjà masquée et garde sa garantie.
            $payload = $this->transport->redact($this->builder->build($e));

            $queue = $this->resolveQueue();

            if ($queue !== false) {
                $job = new SendExceptionToMonitor($payload);

                if (is_string($queue)) {
                    $job->onConnection($queue);
                }

                // Dispatcher::class, not the 'bus' alias: no such alias exists,
                // so make('bus') raised a BindingResolutionException that report()'s
                // own catch swallowed, and a queued report was silently dropped.
                // LogCollector already resolves it this way.
                $this->app->make(Dispatcher::class)->dispatch($job);

                return;
            }

            $this->transport->send($payload);
        } catch (Throwable) {
            // Reporting must never break the host application.
        }
    }

    private function shouldReport(): bool
    {
        if (! ($this->config['enabled'] ?? false)) {
            return false;
        }

        if (blank($this->config['url'] ?? null) || blank($this->config['key'] ?? null)) {
            return false;
        }

        $environments = $this->config['environments'] ?? [];

        if (! empty($environments) && ! in_array($this->app->environment(), $environments, true)) {
            return false;
        }

        if ($this->onIgnoredPath()) {
            return false;
        }

        return true;
    }

    /**
     * Whether the request being served is one whose exceptions are not reported.
     *
     * Anything unexpected here reads as "no match" and reporting carries on:
     * losing a report is the worse of the two failures. A console command
     * carries a dummy request whose path is "/", which matches nothing.
     */
    private function onIgnoredPath(): bool
    {
        try {
            $patterns = $this->config['ignore_paths'] ?? [];

            if (! is_array($patterns)) {
                return false;
            }

            $patterns = array_values(array_filter($patterns, 'is_string'));

            if ($patterns === [] || ! $this->app->bound('request')) {
                return false;
            }

            $request = $this->app->make('request');

            return $request instanceof Request && $request->is(...$patterns);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * La valeur de MONITOR_QUEUE, ou false si elle ne désigne rien.
     *
     * C'est une CONNEXION de file, pas un nom de file, et le nom de la variable
     * laisse croire l'inverse : MONITOR_QUEUE=default, ou =monitoring, ou =high
     * sont les valeurs qu'on écrit naturellement. Aucune n'est une clé de
     * queue.connections, donc Queue::connection() levait, et le catch de
     * report() avalait l'exception : le SDK s'arrêtait entièrement de rapporter,
     * pour toujours, sans un mot nulle part.
     *
     * Retomber en synchrone plutôt que de ne rien faire : un rapport qui part
     * en bloquant la requête vaut mieux qu'un rapport qui ne part jamais, et le
     * warning dit quoi écrire à la place.
     */
    private function resolveQueue(): string|bool
    {
        $queue = $this->config['queue'] ?? false;

        if (! is_string($queue)) {
            return $queue !== false;
        }

        $connections = array_keys((array) $this->app['config']->get('queue.connections', []));

        if (in_array($queue, $connections, true)) {
            return $queue;
        }

        $this->app['log']->warning(
            "Quiet Guard: MONITOR_QUEUE names no queue CONNECTION ({$queue}); reporting synchronously instead.",
            ['available' => $connections, Transport::INTERNAL => true],
        );

        return false;
    }
}
