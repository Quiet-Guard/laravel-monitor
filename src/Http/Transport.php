<?php

namespace LaBoiteACode\LaravelMonitor\Http;

use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use Throwable;

class Transport
{
    public function __construct(
        private readonly ?string $url,
        private readonly ?string $key,
        private readonly int $timeout,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Send a payload to the monitor server. Never throws: monitoring must not
     * break the host application.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        if (blank($this->url) || blank($this->key)) {
            return false;
        }

        try {
            $response = Http::asJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->acceptJson()
                ->post(rtrim($this->url, '/').'/api/v1/ingest', $payload);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->warning('LaravelMonitor: failed to report exception', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
