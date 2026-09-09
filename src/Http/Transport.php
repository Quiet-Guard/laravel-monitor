<?php

namespace QuietGuard\LaravelMonitor\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use QuietGuard\Monitor\Config;
use QuietGuard\Monitor\Support\Cut;
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
    /**
     * Masque les valeurs par leur FORME, où qu'elles soient.
     *
     * Publique, parce qu'il faut pouvoir l'appeler AVANT que la charge puisse
     * être sérialisée quelque part. Le masquage se faisait ici seulement, donc
     * sur le fil : avec MONITOR_QUEUE actif, les adresses e-mail, les IBAN et
     * les numéros de carte que ce redacteur existe pour arrêter atterrissaient
     * en clair dans la table jobs du client, et définitivement dans
     * failed_jobs, à l'intérieur de sa propre base et de ses sauvegardes.
     *
     * Appelé deux fois, il ne fait rien la seconde : « [redacted:email] » ne
     * contient plus d'adresse. La garantie que porte le commentaire de $redactor,
     * « tout ce qui atteint le fil est passé par là », reste donc entière.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        // La borne vient APRÈS le masquage, et c'est l'ordre qui compte :
        // masquer REMPLACE une valeur par une étiquette plus longue, donc une
        // charge coupée avant pouvait repasser au-dessus de la limite du
        // serveur et se faire refuser par le même 422 silencieux que la
        // troncature existe pour empêcher. Un message de numéros coupé à 8192
        // en ressortait à plus de douze mille.
        return Cut::payload($this->redactor?->redactAll($payload) ?? $payload);
    }

    /**
     * Send an exception payload to the monitor server.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): bool
    {
        return $this->post('/api/v1/ingest', $this->redact($payload), 'exception');
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

        return $this->post('/api/v1/logs', $this->redact(['logs' => $logs]), 'logs');
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
        $url = $this->baseUrl().'/api/v1/encryption-key';

        if (! $this->isConfigured()) {
            $this->lastError = 'MONITOR_KEY is empty.';

            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->get($url);

            if ($response->successful()) {
                $this->lastError = null;

                return $response->json();
            }

            // The server answers a different sentence for each of these, and
            // all four used to arrive at the caller as one null: encryption
            // never enabled (404), a key that is not this project's (401), a
            // plan without backups (403), a URL that is not the server (404
            // again, from somebody else's application). The caller then told
            // the reader to go and enable encryption, which fixes three of them
            // not at all.
            $this->lastError = $this->explain($response, $url);

            return null;
        } catch (Throwable $e) {
            $this->lastError = "Could not reach {$url}: ".$e->getMessage();

            $this->logger->warning('Quiet Guard: failed to fetch encryption key', [
                'error' => $e->getMessage(),
                'url' => $url,
                self::INTERNAL => true,
            ]);

            return null;
        }
    }

    /** The server's own words about the last call that failed; null after a success. */
    private ?string $lastError = null;

    /**
     * La clé est la seule chose que nous ne pouvons pas deviner.
     *
     * L'adresse en est une, depuis que le coeur la remplit avec le service
     * hébergé. Exiger les deux refusait une installation à laquelle il ne
     * manquait rien d'irremplaçable, sous un message qui accusait la clé.
     */
    private function isConfigured(): bool
    {
        return filled($this->key);
    }

    /**
     * La racine du serveur, normalisée par le coeur et par lui seul.
     *
     * Chaque méthode ci-dessous ajoute son propre /api/v1/..., donc une adresse
     * qui finit déjà par /api/v1 produit /api/v1/api/v1/... et répond 404 à
     * tout. Ce n'est pas une hypothèse : « l'URL de base de votre serveur » et
     * « le point d'entrée d'ingestion » se lisent comme la même chose, le
     * dashboard affiche l'adresse de l'API, et l'échec qui suit est silencieux
     * et ressemble à une mauvaise clé. Il a coûté une soirée à une vraie
     * installation.
     *
     * La règle vit dans QuietGuard\Monitor\Config et nulle part ailleurs.
     * Écrite ici en second, elle aurait divergé de celle du coeur au premier
     * changement, ce qui est exactement ce que le commentaire de Cut décrit.
     */
    private function baseUrl(): string
    {
        return Config::normaliseUrl($this->url);
    }

    /**
     * What went wrong, in terms the reader can act on.
     *
     * The server writes a specific sentence for every rejection it can explain,
     * and every one of them used to be thrown away and replaced by a guess at
     * the caller. The status and the address matter as much as the sentence: a
     * 404 says the URL is wrong, a 401 says the key is, and telling the reader
     * to "check MONITOR_URL / MONITOR_KEY" says neither.
     */
    private function explain(Response $response, string $url): string
    {
        $message = is_string($m = $response->json('message')) ? trim($m) : '';

        return $message !== ''
            ? "HTTP {$response->status()} from {$url}: {$message}"
            : "HTTP {$response->status()} from {$url}";
    }

    /**
     * Upload an already-encrypted backup blob.
     *
     * @return array<string, mixed>|null the created backup metadata, or null on failure
     */
    public function uploadBackup(string $type, ?string $name, string $filePath): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $request = Http::acceptJson()
                ->timeout(max($this->timeout, 120))
                ->withToken($this->key)
                ->attach('file', fopen($filePath, 'rb'), basename($filePath));

            $response = $request->post($this->baseUrl().'/api/v1/backups', array_filter([
                'type' => $type,
                'name' => $name,
            ]));

            if ($response->successful()) {
                $this->lastError = null;

                return $response->json();
            }

            // Keep what the server said. A 422 names the field it refused and a
            // 413 names the quota, and both used to be replaced by a guess at
            // the caller ("check MONITOR_URL / MONITOR_KEY and your storage
            // quota") that is wrong for every rejection the server can explain.
            $this->lastError = $this->explain($response, $this->baseUrl().'/api/v1/backups');

            return null;
        } catch (Throwable $e) {
            $this->lastError = 'Could not reach '.$this->baseUrl().'/api/v1/backups: '.$e->getMessage();

            $this->logger->warning('Quiet Guard: failed to upload backup', [
                'error' => $e->getMessage(),
                self::INTERNAL => true,
            ]);

            return null;
        }
    }

    /**
     * What the server said about the last upload it refused, when it said
     * anything: null for a transport failure, where there is nothing to quote.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * The backups this project already holds, newest first.
     *
     * The endpoint has existed since the vault shipped and no client could
     * reach it: a customer who lost the id printed at upload had the dashboard
     * and nothing else, from a command-line tool. It is also what makes
     * rotation possible at all, since deleting the oldest requires knowing
     * which ones there are.
     *
     * @return array<int, array<string, mixed>>|null null on any failure, never an empty list
     */
    public function listBackups(): ?array
    {
        $url = $this->baseUrl().'/api/v1/backups';

        if (! $this->isConfigured()) {
            $this->lastError = 'MONITOR_KEY is empty.';

            return null;
        }

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->get($url);

            if ($response->successful()) {
                $this->lastError = null;

                return (array) $response->json('backups', []);
            }

            $this->lastError = $this->explain($response, $url);

            return null;
        } catch (Throwable $e) {
            $this->lastError = "Could not reach {$url}: ".$e->getMessage();

            return null;
        }
    }

    /**
     * Delete one backup, blob and row.
     *
     * The server refuses with 500 rather than 204 when the blob survives the
     * attempt, which is the honest answer and the one rotation must respect:
     * dropping a row whose ciphertext is still on disk would leave the customer
     * paying for bytes nothing can name.
     */
    public function deleteBackup(string $id): bool
    {
        $url = $this->baseUrl().'/api/v1/backups/'.$id;

        if (! $this->isConfigured()) {
            $this->lastError = 'MONITOR_KEY is empty.';

            return false;
        }

        try {
            $response = Http::acceptJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->delete($url);

            if ($response->successful()) {
                $this->lastError = null;

                return true;
            }

            $this->lastError = $this->explain($response, $url);

            return false;
        } catch (Throwable $e) {
            $this->lastError = "Could not reach {$url}: ".$e->getMessage();

            return false;
        }
    }

    /**
     * Download an encrypted backup blob to a local path.
     */
    public function downloadBackup(string $id, string $destPath): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::timeout(max($this->timeout, 120))
                ->withToken($this->key)
                ->withOptions(['sink' => $destPath])
                ->get($this->baseUrl().'/api/v1/backups/'.$id);

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
        $url = $this->baseUrl().$path;

        if (! $this->isConfigured()) {
            $this->lastError = 'MONITOR_KEY is empty.';

            return false;
        }

        try {
            $response = Http::asJson()
                ->timeout($this->timeout)
                ->withToken($this->key)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->successful()) {
                $this->lastError = null;

                return true;
            }

            $this->lastError = $this->explain($response, $url);

            // A refusal the server took the trouble to explain is worth a line
            // in the customer's own log: a 402 during dunning, a 403 on a plan
            // gate and a 422 on a field are all invisible otherwise, and the
            // exception path is the one place nobody is watching a console.
            $this->logger->warning("Quiet Guard: {$kind} refused", [
                'error' => $this->lastError,
                self::INTERNAL => true,
            ]);

            return false;
        } catch (Throwable $e) {
            $this->lastError = "Could not reach {$url}: ".$e->getMessage();

            $this->logger->warning("Quiet Guard: failed to send {$kind}", [
                'error' => $e->getMessage(),
                'url' => $url,
                self::INTERNAL => true,
            ]);

            return false;
        }
    }
}
