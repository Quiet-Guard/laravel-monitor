<?php

namespace LaBoiteACode\LaravelMonitor;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LaBoiteACode\LaravelMonitor\Http\Transport;
use LaBoiteACode\LaravelMonitor\Support\PayloadBuilder;
use LaBoiteACode\LaravelMonitor\Support\Scrubber;
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
                (int) ($config['trace_limit'] ?? 50),
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
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/monitor.php' => $this->app->configPath('monitor.php'),
            ], 'monitor-config');
        }

        $this->registerExceptionHook();
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
}
