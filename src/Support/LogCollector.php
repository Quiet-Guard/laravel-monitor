<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Jobs\SendLogsToMonitor;
use QuietGuard\Monitor\Support\Cut;
use Throwable;

class LogCollector
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $entries = [];

    private bool $flushing = false;

    private bool $normalizing = false;

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
     * recorded while flushing or while unwrapping a context (a jsonSerialize()
     * that logs) are ignored, to avoid recursion.
     *
     * @param  array<string, mixed>  $context
     */
    public function add(string $level, string $message, array $context = []): void
    {
        if ($this->flushing || $this->normalizing) {
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

        // Un message vide est refusé par le serveur (required), et un message
        // trop long l'est aussi (max:8192). Dans les deux cas la validation
        // échoue pour le LOT ENTIER : une seule ligne mal formée faisait perdre
        // les deux cents autres, sans que personne ne l'apprenne.
        // Soft, at a whitespace past the limit: the hard cut comes after
        // masking (Transport::redact), so a token straddling the limit is
        // masked whole rather than halved in clear.
        $message = Cut::soft($message, Cut::LIMITS['message']) ?? '';

        if (trim($message) === '') {
            $message = '(empty log message)';
        }

        // Le serveur plafonne un lot à 500. Une configuration au-dessus était
        // acceptée ici et rejetée là-bas à chaque envoi, donc rien ne partait
        // jamais.
        $maxBatch = min(500, max(1, (int) ($this->config['max_batch'] ?? 200)));

        // At capacity, flush what we have instead of silently dropping entries.
        if (count($this->entries) >= $maxBatch) {
            $this->flush();
        }

        // jsonSerialize() tourne ici : un accesseur qui logge
        // réentrerait dans add() jusqu'à la limite de pile (un segfault sur
        // PHP 8.2), d'où le drapeau, comme pendant le flush.
        $this->normalizing = true;

        try {
            $context = $this->normalize($context);
        } finally {
            $this->normalizing = false;
        }

        $this->entries[] = [
            'level' => strtolower($level),
            'message' => $message,
            'context' => $this->scrubber->scrub($context),
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

            // La même lecture que le chemin des exceptions, qui l'avait et que
            // celui-ci n'avait pas : une connexion inconnue levait dans le
            // dispatch, le catch du provider avalait, et le lot était déjà
            // retiré du tampon. Jusqu'à cinq cents entrées perdues, à chaque
            // vidange, sans un mot.
            $queue = QueueConnection::resolve($this->app, $this->config['queue'] ?? false);

            if ($queue !== false) {
                // Masqué avant la file, comme pour les exceptions : un contexte
                // de log porte les mêmes formes (adresse, IBAN, carte) et la
                // table jobs du client n'est pas un endroit où les laisser.
                $job = new SendLogsToMonitor($this->transport->redact(['logs' => $batch])['logs'] ?? $batch);

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
        // Un tableau neuf, jamais une écriture dans celui qu'on parcourt : un
        // élément qui est une référence PHP aurait transmis l'écriture à la
        // variable de l'application. Logger ne modifie rien chez l'appelant.
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    /**
     * @param  array<int, true>  $path  ids of the objects being unwrapped on
     *                                  this branch, to stop a cycle
     */
    private function normalizeValue(mixed $value, int $depth = 0, array $path = []): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        // La profondeur seule ne borne pas un cycle : un parent dont douze
        // enfants renvoient vers lui épuisait 512 Mo en largeur. Le chemin,
        // comme la détection de récursion de json_encode, s'en charge.
        if ($depth > 32) {
            return '[depth]';
        }

        if (is_array($value)) {
            // Neuf, pour la même raison que normalize() : get_object_vars()
            // et jsonSerialize() conservent les références.
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item, $depth + 1, $path);
            }

            return $normalized;
        }

        if (! is_object($value)) {
            return gettype($value);
        }

        if (isset($path[spl_object_id($value)])) {
            return '[recursion]';
        }

        $path[spl_object_id($value)] = true;

        // Déplié ICI, avant le scrubber : un objet gardé entier n'était
        // déplié que par le json_encode du transport, après tout masquage,
        // donc une Collection portant 'password' partait en clair (et entrait
        // en clair dans la table jobs en mode file). On déplie exactement ce
        // que json_encode aurait envoyé, rien de plus : jsonSerialize(), ou
        // les propriétés publiques d'un Stringable, JAMAIS sa chaîne (celle
        // d'une Response ou d'une Request porte Set-Cookie, Cookie et
        // Authorization). Un objet seulement Arrayable garde son nom de
        // classe comme en 0.7.0 : son toArray() rendrait la saisie d'un
        // ValidatedInput, d'un formulaire Livewire ou d'un appel MCP.
        try {
            $data = match (true) {
                $value instanceof \JsonSerializable => $value->jsonSerialize(),
                $value instanceof \Stringable => get_object_vars($value),
                default => $value::class,
            };
        } catch (Throwable) {
            // Un objet qui lève ne coûte que sa valeur, pas la ligne de log.
            return $value::class;
        }

        return $this->normalizeValue($data, $depth + 1, $path);
    }
}
