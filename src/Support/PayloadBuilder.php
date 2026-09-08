<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use QuietGuard\Monitor\Support\Cut;
use Throwable;

class PayloadBuilder
{
    public function __construct(
        private readonly Application $app,
        private readonly Scrubber $scrubber,
        private readonly int $traceLimit = 0,
        private readonly ?string $release = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Throwable $e): array
    {
        return [
            'exception' => [
                'class' => Cut::to($e::class, 255),
                'message' => Cut::to($e->getMessage(), 8192),
                'code' => $e->getCode(),
                'file' => Cut::to($e->getFile(), 1024),
                'line' => $e->getLine(),
                'trace' => $this->trace($e),
            ],
            'context' => $this->context(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function trace(Throwable $e): array
    {
        $frames = [];

        $trace = $this->traceLimit > 0
            ? array_slice($e->getTrace(), 0, $this->traceLimit)
            : $e->getTrace();

        foreach ($trace as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => $frame['line'] ?? null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
                'type' => $frame['type'] ?? null,
            ];
        }

        return $frames;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        $context = [
            'environment' => $this->app->environment(),
            'release' => Cut::to($this->release, 255),
            'php_version' => PHP_VERSION,
            'laravel_version' => $this->app->version(),
            'occurred_at' => now()->toIso8601String(),
        ];

        if ($this->app->runningInConsole()) {
            $context['source'] = 'console';

            return $context;
        }

        /** @var Request $request */
        $request = $this->app->make('request');

        $context['source'] = 'http';
        $context['url'] = $request->fullUrl();
        $context['method'] = $request->method();
        $context['request'] = $this->scrubber->scrub([
            'headers' => $this->headers($request),
            'query' => $request->query(),
            'body' => $request->except(['password', 'password_confirmation']),
        ]);

        if ($user = $request->user()) {
            $context['user'] = ['id' => $user->getAuthIdentifier()];
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(Request $request): array
    {
        return array_map(
            fn (array $values) => count($values) === 1 ? $values[0] : $values,
            $request->headers->all(),
        );
    }
}
