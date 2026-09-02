<?php

namespace QuietGuard\LaravelMonitor\Http;

use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use QuietGuard\Monitor\Support\ValueRedactor;
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
        // Value masking is the last thing that happens to a payload. Doing it
        // here rather than in each builder means a field added later cannot be
        // forgotten: whatever reaches the wire has been through it. Null keeps
        // the previous behaviour for anyone constructing this by hand.
        private readonly ?ValueRedactor $redactor = null,
    ) {}

    /**
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function redacted(array $payload): array
    {
        return $this->redactor?->redactAll($payload) ?? $payload;
    }

    /**
     * Send an exception payload to the monitor server.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        return $this->post('/api/v1/ingest', $this->redacted($payload), 'exception');
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

        return $this->post('/api/v1/logs', $this->redacted(['logs' => $logs]), 'logs');
    }

    /**
     * Send the project's dependency snapshot to the monitor server.
     *
     * @param  array<int, array<string, mixed>>  $packages
     */
    public function sendDependencies(array $packages): bool
    {
        if ($packages === []) {
            return false;
        }

        return $this->post('/api/v1/dependencies', ['packages' => $packages], 'dependencies');
    }

    /**
     * Ping a scheduled-task heartbeat (auto-registers on first ping).
     */
    public function ping(string $slug): bool
    {
        return $this->post('/api/v1/heartbeats/'.rawurlencode($slug), [], 'heartbeat');
    }

    /**
     * Fetch the team's encryption key material (public key + wrapped private key
     * + salt) for backup sealing / restoring.
     *
     * @return array<string, mixed>|null
     */
    public function getEncryptionKey(): ?array
    {
        if (blank($this->url) || blank($this->key)) {
            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->get(rtrim($this->url, '/').'/api/v1/encryption-key');

            return $response->successful() ? $response->json() : null;
        } catch (Throwable $e) {
            $this->logger->warning('Quiet Guard: failed to fetch encryption key', [
                'error' => $e->getMessage(),
                self::INTERNAL => true,
            ]);

            return null;
        }
    }

    /**
     * Upload an already-encrypted backup blob.
     *
     * @return array<string, mixed>|null the created backup metadata, or null on failure
     */
    public function uploadBackup(string $type, ?string $name, string $filePath): ?array
    {
        if (blank($this->url) || blank($this->key)) {
            return null;
        }

        try {
            $request = Http::acceptJson()
                ->timeout(max($this->timeout, 120))
                ->withToken($this->key)
                ->attach('file', fopen($filePath, 'rb'), basename($filePath));

            $response = $request->post(rtrim($this->url, '/').'/api/v1/backups', array_filter([
                'type' => $type,
                'name' => $name,
            ]));

            return $response->successful() ? $response->json() : null;
        } catch (Throwable $e) {
            $this->logger->warning('Quiet Guard: failed to upload backup', [
                'error' => $e->getMessage(),
                self::INTERNAL => true,
            ]);

            return null;
        }
    }

    /**
     * Download an encrypted backup blob to a local path.
     */
    public function downloadBackup(string $id, string $destPath): bool
    {
        if (blank($this->url) || blank($this->key)) {
            return false;
        }

        try {
            $response = Http::timeout(max($this->timeout, 120))
                ->withToken($this->key)
                ->withOptions(['sink' => $destPath])
                ->get(rtrim($this->url, '/').'/api/v1/backups/'.$id);

            return $response->successful();
        } catch (Throwable $e) {
            $this->logger->warning('Quiet Guard: failed to download backup', [
                'error' => $e->getMessage(),
                self::INTERNAL => true,
            ]);

            return false;
        }
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
            $this->logger->warning("Quiet Guard: failed to send {$kind}", [
                'error' => $e->getMessage(),
                self::INTERNAL => true,
            ]);

            return false;
        }
    }
}
