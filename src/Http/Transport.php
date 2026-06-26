<?php

namespace LaBoiteACode\LaravelMonitor\Http;

use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

class Transport
{
    /**
     * Context marker so the client's own failure logs are never re-captured by
     * the log collector (which would cause a feedback loop).
     */
    public const INTERNAL = '__monitor';

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $key,
        private readonly int $timeout,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Send an exception payload to the monitor server.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        return $this->post('/api/v1/ingest', $payload, 'exception');
    }

    /**
     * Send a batch of log entries to the monitor server.
     *
     * @param  array<int, array<string, mixed>>  $logs
     */
    public function sendLogs(array $logs): bool
    {
        if ($logs === []) {
            return true;
        }

        return $this->post('/api/v1/logs', ['logs' => $logs], 'logs');
    }

    /**
     * Never throws: monitoring must not break the host application.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(string $path, array $payload, string $kind): bool
    {
        if (blank($this->url) || blank($this->key)) {
            return false;
        }

        try {
            $response = Http::asJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->acceptJson()
                ->post(rtrim($this->url, '/').$path, $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->warning("LaravelMonitor: failed to send {$kind}", [
                'error' => $e->getMessage(),
                self::INTERNAL => true,
            ]);

            return false;
        }
    }
}
