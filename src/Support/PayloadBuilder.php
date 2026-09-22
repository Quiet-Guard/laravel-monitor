<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use QuietGuard\Monitor\Payload\ExceptionPayloadBuilder;
use QuietGuard\Monitor\Support\Cut;
use Throwable;

class PayloadBuilder
{
    public function __construct(
        private readonly Application $app,
        private readonly Scrubber $scrubber,
        private readonly int $traceLimit = 0,
        private readonly ?string $release = null,
        private readonly bool $codeSnippets = true,
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
     * The core builder's frames (the throw site first, the limit counting it,
     * snippets on application frames), then the scrub-line rule by the same
     * key list that masks the request: code is where a hardcoded secret lives.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trace(Throwable $e): array
    {
        return $this->scrubber->scrubSnippets(
            ExceptionPayloadBuilder::frames($e, $this->traceLimit, $this->codeSnippets)
        );
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
        // The URL goes through the scrubber like everything else: a password
        // reset link carries its token and its signature in the query string,
        // and this value used to be set outside it.
        $context['url'] = $this->scrubber->scrubUrl($request->fullUrl());
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
