<?php

namespace QuietGuard\LaravelMonitor;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\ServiceProvider;
use QuietGuard\LaravelMonitor\Console\BackupCommand;
use QuietGuard\LaravelMonitor\Console\HeartbeatCommand;
use QuietGuard\LaravelMonitor\Console\ListBackupsCommand;
use QuietGuard\LaravelMonitor\Console\PerformanceFlushCommand;
use QuietGuard\LaravelMonitor\Console\ReportDependenciesCommand;
use QuietGuard\LaravelMonitor\Console\RestoreCommand;
use QuietGuard\LaravelMonitor\Console\TestCommand;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Support\AutomaticHeartbeats;
use QuietGuard\LaravelMonitor\Support\BrowserScript;
use QuietGuard\LaravelMonitor\Support\LogCollector;
use QuietGuard\LaravelMonitor\Support\PayloadBuilder;
use QuietGuard\LaravelMonitor\Support\PerformanceFlusher;
use QuietGuard\LaravelMonitor\Support\PerformanceRecorder;
use QuietGuard\LaravelMonitor\Support\ReleaseResolver;
use QuietGuard\LaravelMonitor\Support\Scrubber;
use QuietGuard\Monitor\Config;
use QuietGuard\Monitor\Support\ValueRedactor;
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
                $this->release($app, $config),
                (bool) ($config['code_snippets'] ?? true),
                // Null, not [], when the key is absent: a configuration
                // published before the key existed carries no opinion, and
                // the builder's own list applies. An explicit [] sends every
                // header masked, which is a choice.
                is_array($config['request_headers'] ?? null) ? $config['request_headers'] : null,
            );
        });

        $this->app->singleton(Transport::class, function (Application $app) {
            $config = $app['config']->get('monitor');

            return new Transport(
                $config['url'] ?? null,
                $config['key'] ?? null,
                (int) ($config['timeout'] ?? 3),
                $app->make('log'),
                // Null (the template's value, and an absent key) is the
                // client's own list: a published configuration names no
                // class of the package, so removing the package never makes
                // that file fatal. A list written out is read as it is.
                new ValueRedactor(
                    is_array($config['redact'] ?? null) ? $config['redact'] : ValueRedactor::PATTERNS,
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

        // Not a singleton: a layout reads the configuration as it is when it
        // renders, like the gates of the exception path.
        $this->app->bind(BrowserScript::class, function (Application $app) {
            $config = $app['config']->get('monitor');

            return new BrowserScript($app, $config, $this->release($app, $config));
        });

        $this->app->singleton(LogCollector::class, function (Application $app) {
            $config = $app['config']->get('monitor');

            return new LogCollector(
                $app,
                $app->make(Transport::class),
                $app->make(Scrubber::class),
                array_merge($config['logs'] ?? [], [
                    'release' => $this->release($app, $config),
                    'queue' => $config['queue'] ?? false,
                ]),
            );
        });

        // Lazy: nothing is built, and no listener is attached (registerPerformance),
        // unless MONITOR_PERFORMANCE is on.
        $this->app->singleton(PerformanceFlusher::class, function (Application $app) {
            $config = $app['config']->get('monitor');
            $instance = PerformanceFlusher::instanceId();

            return new PerformanceFlusher(
                $app,
                $app->make(Transport::class),
                $this->performancePath($app),
                $instance,
                PerformanceFlusher::offsetFor($instance),
                (string) $app->environment(),
                $this->release($app, $config),
            );
        });

        $this->app->singleton(PerformanceRecorder::class, function (Application $app) {
            $config = $app['config']->get('monitor');
            $host = parse_url(Config::normaliseUrl($config['url'] ?? null), PHP_URL_HOST);

            return new PerformanceRecorder(
                $app,
                $this->performancePath($app),
                PerformanceFlusher::offsetFor(PerformanceFlusher::instanceId()),
                is_string($host) ? strtolower($host) : null,
                is_array($config['ignore_paths'] ?? null) ? $config['ignore_paths'] : [],
                fn () => $app->make(PerformanceFlusher::class)->flushDue(),
            );
        });
    }

    /**
     * MONITOR_PERFORMANCE_PATH, otherwise storage/framework/quiet-guard/performance.
     * Never the system temporary directory: systemd's PrivateTmp gives FPM
     * and cron two different ones.
     */
    private function performancePath(Application $app): string
    {
        $path = $app['config']->get('monitor.performance.path');

        if (! is_string($path) || trim($path) === '') {
            return $app->storagePath('framework/quiet-guard/performance');
        }

        $path = rtrim(trim($path), '/');

        return str_starts_with($path, '/') ? $path : $app->basePath($path);
    }

    /**
     * The release the exception and log payloads carry: `MONITOR_RELEASE` when
     * it is set, otherwise the checkout's own git commit (or a `REVISION` file),
     * unless `release_auto` is off. One definition, so the two payloads cannot
     * name two versions.
     *
     * @param  array<string, mixed>  $config
     */
    private function release(Application $app, array $config): ?string
    {
        $configured = $config['release'] ?? null;

        if (! ($config['release_auto'] ?? true)) {
            $configured = trim((string) $configured);

            return $configured === '' ? null : $configured;
        }

        return ReleaseResolver::resolve($configured, $app->basePath());
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
                ListBackupsCommand::class,
                RestoreCommand::class,
                TestCommand::class,
                PerformanceFlushCommand::class,
            ]);

            $this->registerAutomaticHeartbeats();
        }

        $this->registerExceptionHook();
        $this->registerLogHook();
        $this->registerSchedulerMacro();
        $this->registerBrowserDirective();
        $this->registerPerformance();
    }

    /**
     * Aggregated performance measurement. Off by default, and off means no
     * listener at all: the gate is read once, at boot.
     *
     * Per query and per outgoing call, a few array operations in memory; at
     * the end of a request or a job, one line appended to a local file. Once
     * a minute, one summary leaves: from the scheduled task the SDK adds, from
     * a worker's loop, or from the first request of a new window.
     */
    private function registerPerformance(): void
    {
        if (! PerformanceRecorder::active($this->app)) {
            return;
        }

        $recorder = $this->app->make(PerformanceRecorder::class);
        $events = $this->app['events'];

        $events->listen(QueryExecuted::class, fn (QueryExecuted $event) => $recorder->onQuery($event));
        $events->listen(RequestSending::class, fn (RequestSending $event) => $recorder->onRequestSending($event));
        $events->listen(ResponseReceived::class, fn (ResponseReceived $event) => $recorder->onResponse($event));
        $events->listen(ConnectionFailed::class, fn (ConnectionFailed $event) => $recorder->onConnectionFailed($event));
        $events->listen(RequestHandled::class, fn (RequestHandled $event) => $recorder->onRequestHandled($event->request, $event->response));
        $events->listen('Laravel\Octane\Events\RequestReceived', fn () => $recorder->onOctaneRequest());
        $events->listen(JobProcessing::class, fn (JobProcessing $event) => $recorder->onJobProcessing($event));
        $events->listen(JobProcessed::class, fn () => $recorder->onJobEnded(false));
        $events->listen(JobExceptionOccurred::class, fn () => $recorder->onJobEnded(true));
        $events->listen(JobFailed::class, fn () => $recorder->onJobEnded(true));
        $this->app->terminating(fn () => $recorder->onTerminating());

        // Once a window per worker. The Worker stops the daemon on a false
        // answer from a Looping listener: this one answers nothing.
        $lastWindow = null;
        $events->listen(Looping::class, function () use (&$lastWindow): void {
            try {
                $flusher = $this->app->make(PerformanceFlusher::class);
                $window = PerformanceFlusher::windowAt(now()->getTimestamp(), $flusher->offset());

                if ($window !== $lastWindow) {
                    $lastWindow = $window;
                    $flusher->flushDue();
                }
            } catch (Throwable) {
            }
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('monitor:performance:flush')->everyMinute()->runInBackground();
        });
    }

    /**
     * `@quietGuardBrowser` in a layout's <head> prints the browser error
     * script inline, or nothing without MONITOR_BROWSER_KEY. Registered once
     * the Blade compiler exists, so an application without views loads no
     * view component for it.
     */
    private function registerBrowserDirective(): void
    {
        $this->callAfterResolving('blade.compiler', function (object $blade): void {
            $blade->directive('quietGuardBrowser', fn (): string => '<?php echo app(\\'.BrowserScript::class.'::class)->render(); ?>');
        });
    }

    /**
     * The scheduler and queue heartbeats, pinged from the worker's own loop
     * and from schedule:run's own start. Only in console, where both run.
     * Registered whatever the config says: the gate is read at every event,
     * since the config can change after boot and a cached one must not
     * disagree with it.
     */
    private function registerAutomaticHeartbeats(): void
    {
        $events = $this->app['events'];

        // The Worker dispatches Looping with until() and stops the daemon on
        // a false answer. A void listener answers null: keep it that way.
        $events->listen(Looping::class, function (Looping $event): void {
            AutomaticHeartbeats::onLooping($this->app, $event);
        });

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            AutomaticHeartbeats::onJobProcessing($this->app, $event);
        });

        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            AutomaticHeartbeats::onCommandStarting($this->app, $event);
        });
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

        // The key alone: an empty MONITOR_URL is a configured client that
        // takes the hosted address (Config::normaliseUrl), not an absent one.
        if (blank($config['key'] ?? null)) {
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
