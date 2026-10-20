<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use QuietGuard\Monitor\Payload\ExceptionPayloadBuilder;
use QuietGuard\Monitor\Support\Cut;
use Throwable;

class PayloadBuilder
{
    /**
     * The request headers sent in clear when the configuration names none
     * (`monitor.request_headers`). An allow list, not a block list: a header
     * is a name chosen by whoever sent the request, so a list of the ones to
     * mask is a list of the ones somebody thought of, and the next proxy, SDK
     * or browser adds one nobody did. These say what the request was and how
     * it was asked for, which is what a report needs, and none of them has
     * ever carried a credential.
     *
     * The headers that carry the visitor's IP address are not here on
     * purpose: they are personal data, and keeping them is the customer's
     * decision as controller, which the processing agreement leaves to them.
     * A customer with a legal basis adds them to the list.
     *
     * @var array<int, string>
     */
    public const HEADERS = [
        'accept', 'accept-encoding', 'accept-language', 'cache-control',
        'content-length', 'content-type', 'host', 'origin', 'user-agent',
        'x-requested-with',
        // What a Livewire or an Inertia request says about itself: that it
        // is one, and which asset version it was built against.
        'x-livewire', 'x-inertia', 'x-inertia-version',
    ];

    /**
     * @param  array<int, string>|null  $headers  the headers sent in clear; null takes HEADERS
     */
    public function __construct(
        private readonly Application $app,
        private readonly Scrubber $scrubber,
        private readonly int $traceLimit = 0,
        private readonly ?string $release = null,
        private readonly bool $codeSnippets = true,
        private readonly ?array $headers = null,
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
        // The URL goes through the scrubber like everything else; this value
        // used to be set outside it. A password reset link carries its token
        // either in the query, beside a signature (Filament's), where the
        // values the key list names are masked, or in the PATH (Laravel's
        // own /reset-password/{token}, which Breeze, Fortify and Jetstream
        // send), where a segment made only of letters and digits, forty or
        // more, is masked. Before that, every route parameter whose NAME the
        // key list matches is masked by name, whatever its length or shape.
        $context['url'] = $this->scrubber->scrubUrl($this->maskRouteParameters($request, $request->fullUrl()));
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
     * Every header the request carries, by name: its value when the name is
     * on the allow list, the mask otherwise. The NAME stays, since knowing
     * that the request carried an `authorization` or an `x-csrf-token` is
     * what a reader debugging it needs, and the name of a header has never
     * been the secret. The key scrubber still runs over the result, so a
     * name the scrub list masks is masked even when the allow list admits it.
     *
     * @return array<string, mixed>
     */
    private function headers(Request $request): array
    {
        $allowed = array_flip(array_map('strtolower', $this->headers ?? self::HEADERS));
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);

            $headers[$name] = isset($allowed[$name])
                ? (count($values) === 1 ? $values[0] : $values)
                : Scrubber::MASK;
        }

        return $headers;
    }

    /**
     * The URL with the value of every route parameter the key list names
     * replaced by the mask, wherever it sits in the path.
     *
     * The path rule of scrubUrl() recognises a token by its shape, forty
     * letters or digits in a row, which is Laravel's own reset token and
     * nothing shorter. A route written `/reset-password/{token}` says, by the
     * NAME of its parameter, that the segment is a token whatever its length,
     * and the router knows that name: it is read here and masked by it. A
     * parameter that spans several segments (a `where('.*')` catch-all) is
     * replaced as the string it is. Anything unexpected hands the URL back as
     * it came, so the shape rule still applies to it: masking must never be
     * the reason a report is lost.
     */
    private function maskRouteParameters(Request $request, string $url): string
    {
        try {
            $route = $request->route();

            if (! $route instanceof Route) {
                return $url;
            }

            $secrets = [];

            foreach ($route->originalParameters() as $name => $value) {
                if (is_string($name) && is_scalar($value) && (string) $value !== '' && $this->scrubber->masks($name)) {
                    $secrets[] = (string) $value;
                }
            }

            if ($secrets === []) {
                return $url;
            }

            if (preg_match('~^([^?#]*)(.*)$~s', $url, $parts) !== 1) {
                return $url;
            }

            [, $path, $rest] = $parts;
            $mask = rawurlencode(Scrubber::MASK);

            $path = implode('/', array_map(
                fn (string $segment): string => in_array(rawurldecode($segment), $secrets, true) ? $mask : $segment,
                explode('/', $path),
            ));

            foreach ($secrets as $secret) {
                if (str_contains($secret, '/')) {
                    $path = str_replace([$secret, rawurlencode($secret)], $mask, $path);
                }
            }

            return $path.$rest;
        } catch (Throwable) {
            return $url;
        }
    }
}
