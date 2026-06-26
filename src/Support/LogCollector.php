<?php

namespace LaBoiteACode\LaravelMonitor\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use LaBoiteACode\LaravelMonitor\Http\Transport;
use LaBoiteACode\LaravelMonitor\Jobs\SendLogsToMonitor;

class LogCollector
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $entries = [];

    private bool $flushing = false;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly Application $app,
        private readonly Transport $transport,
        private readonly Scrubber $scrubber,
        private readonly array $config,
    ) {}

    /**
     * Record a log message. Entries below the configured threshold, entries
     * carrying an exception (handled by the exception pipeline) and entries
     * recorded while flushing (to avoid recursion) are ignored.
     *
     * @param  array<string, mixed>  $context
     */
    public function add(string $level, string $message, array $context = []): void
    {
        if ($this->flushing) {
            return;
        }

        if (! LogLevel::meetsThreshold($level, $this->config['level'] ?? 'warning')) {
            return;
        }

        // Skip exceptions (handled by the exception pipeline) and the client's
        // own internal failure logs (to avoid a feedback loop).
        if (isset($context['exception']) || isset($context[Transport::INTERNAL])) {
            return;
        }

        // At capacity, flush what we have instead of silently dropping entries.
        if (count($this->entries) >= (int) ($this->config['max_batch'] ?? 200)) {
            $this->flush();
        }

        $this->entries[] = [
            'level' => strtolower($level),
            'message' => $message,
            'context' => $this->scrubber->scrub($this->normalize($context)),
            'environment' => $this->app->environment(),
            'release' => $this->config['release'] ?? null,
            'logged_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Send buffered entries to the monitor server (synchronously or via queue).
     */
    public function flush(): void
    {
        if ($this->entries === [] || $this->flushing) {
            return;
        }

        $this->flushing = true;

        try {
            $batch = $this->entries;
            $this->entries = [];

            $queue = $this->config['queue'] ?? false;

            if ($queue !== false) {
                $job = new SendLogsToMonitor($batch);

                if (is_string($queue)) {
                    $job->onConnection($queue);
                }

                $this->app->make(Dispatcher::class)->dispatch($job);

                return;
            }

            $this->transport->sendLogs($batch);
        } finally {
            $this->flushing = false;
        }
    }

    /**
     * Convert a log context into a JSON-serialisable array.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    private function normalize(array $context): array
    {
        foreach ($context as $key => $value) {
            $context[$key] = $this->normalizeValue($value);
        }

        return $context;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return $this->normalize($value);
        }

        if ($value instanceof \JsonSerializable || $value instanceof \Stringable) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return is_object($value) ? $value::class : gettype($value);
    }
}
