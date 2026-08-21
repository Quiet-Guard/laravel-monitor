<?php

namespace LaBoiteACode\LaravelMonitor;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;
use LaBoiteACode\LaravelMonitor\Console\BackupCommand;
use LaBoiteACode\LaravelMonitor\Console\HeartbeatCommand;
use LaBoiteACode\LaravelMonitor\Console\ReportDependenciesCommand;
use LaBoiteACode\LaravelMonitor\Console\RestoreCommand;
use LaBoiteACode\LaravelMonitor\Http\Transport;
use LaBoiteACode\LaravelMonitor\Support\LogCollector;
use LaBoiteACode\LaravelMonitor\Support\PayloadBuilder;
use LaBoiteACode\LaravelMonitor\Support\Scrubber;
use LaBoiteACode\Monitor\Support\ValueRedactor;
use Throwable;

class LaravelMonitorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/monitor.php', 'monitor');

        $this->app->singleton(Scrubber::class, function (Application $app) {
            return new Scrubber($app['config']->get('monitor.scrub', []));
        });

        $this->app->singleton(PayloadBuilder::class, function (Application $app) {
            $config = $app['config']->get('monitor');

            return new PayloadBuilder(
                $app,
                $app->make(Scrubber::class),
                (int) ($config['trace_limit'] ?? 0),
                $config['release'] ?? null,
            );
        });

        $this->app->singleton(Transport::class, function (Application $app) {
            $config = $app['config']->get('monitor');

            return new Transport(
                $config['url'] ?? null,
                $config['key'] ?? null,
                (int) ($config['timeout'] ?? 3),
                $app->make('log'),
                new ValueRedactor(
                    $config['redact'] ?? ValueRedactor::PATTERNS,
                    $config['redact_custom'] ?? [],
                ),
            );
        });

        $this->app->singleton(Monitor::class, function (Application $app) {
            return new Monitor(
                $app,
                $app->make(PayloadBuilder::class),
                $app->make(Transport::class),
                $app['config']->get('monitor'),
            );
        });

        $this->app->singleton(LogCollector::class, function (Application $app) {
            $config = $app['config']->get('monitor');

            return new LogCollector(
                $app,
                $app->make(Transport::class),
                $app->make(Scrubber::class),
                array_merge($config['logs'] ?? [], [
                    'release' => $config['release'] ?? null,
                    'queue' => $config['queue'] ?? false,
                ]),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/monitor.php' => $this->app->configPath('monitor.php'),
            ], 'monitor-config');

            $this->commands([
                ReportDependenciesCommand::class,
                HeartbeatCommand::class,
                BackupCommand::class,
                RestoreCommand::class,
            ]);
        }

        $this->registerExceptionHook();
        $this->registerLogHook();
        $this->registerSchedulerMacro();
    }

    /**
     * `$schedule->command('reports:send')->daily()->monitorHeartbeat('reports-send')`
     * pings the heartbeat ONLY when the task succeeded, so a crashing task
     * stays silent and triggers the overdue alert. Sugar over
     * `php artisan monitor:heartbeat`.
     */
    private function registerSchedulerMacro(): void
    {
        if (Event::hasMacro('monitorHeartbeat')) {
            return;
        }

        Event::macro('monitorHeartbeat', function (string $slug) {
            /** @var Event $this */
            return $this->onSuccess(function () use ($slug): void {
                // Same gates as exception reporting: the master switch and the
                // environments allowlist apply to every passive pipeline.
                $config = config('monitor');

                if (! ($config['enabled'] ?? false)) {
                    return;
                }

                $environments = $config['environments'] ?? [];

                if ($environments !== [] && ! in_array(app()->environment(), $environments, true)) {
                    return;
                }

                app(Transport::class)->ping($slug);
            });
        });
    }

    /**
     * Hook into the application's exception handler. The reportable callback is
     * additive: it does not replace or suppress the default logging behaviour.
     */
    private function registerExceptionHook(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (Throwable $e): void {
            $this->app->make(Monitor::class)->report($e);
        });
    }

    /**
     * Buffer application logs and flush them once the request/command ends.
     */
    private function registerLogHook(): void
    {
        $config = $this->app['config']->get('monitor');

        if (! ($config['enabled'] ?? false) || ! ($config['logs']['enabled'] ?? false)) {
            return;
        }

        if (blank($config['url'] ?? null) || blank($config['key'] ?? null)) {
            return;
        }

        // The environments allowlist gates logs exactly like exceptions.
        $environments = $config['environments'] ?? [];

        if ($environments !== [] && ! in_array($this->app->environment(), $environments, true)) {
            return;
        }

        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event): void {
            try {
                $this->app->make(LogCollector::class)->add($event->level, $event->message, $event->context ?? []);
            } catch (Throwable) {
                // Capturing logs must never break the host application.
            }
        });

        $flush = function (): void {
            try {
                $this->app->make(LogCollector::class)->flush();
            } catch (Throwable) {
                // Flushing must never break the host application.
            }
        };

        // HTTP requests and console commands flush on terminate; long-running
        // queue workers never "terminate" between jobs, so flush per job too.
        $this->app->terminating($flush);
        $this->app['events']->listen(JobProcessed::class, $flush);
        $this->app['events']->listen(JobFailed::class, $flush);
    }
}
