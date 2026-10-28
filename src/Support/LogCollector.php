<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\Arrayable;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Jobs\SendLogsToMonitor;
use QuietGuard\Monitor\Support\Cut;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

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
        foreach ($context as $key => $value) {
            $context[$key] = $this->normalizeValue($value);
        }

        return $context;
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
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeValue($item, $depth + 1, $path);
            }

            return $value;
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
        // en clair dans la table jobs en mode file). jsonSerialize() d'abord,
        // parce que c'est ce que json_encode aurait appelé. Un Stringable
        // donne ses propriétés publiques, comme json_encode, et JAMAIS sa
        // chaîne : celle d'une Response ou d'une Request porte Set-Cookie,
        // Cookie et Authorization, que ni le scrubber ni le redacteur ne
        // savent masquer. Une Request passe par là AVANT Arrayable : son
        // toArray() est all(), donc un champ au nom anodin (otp, pin, cvc)
        // partirait en clair là où la 0.7.0 n'envoyait que des sacs vides.
        try {
            $data = match (true) {
                $value instanceof Request => get_object_vars($value),
                $value instanceof \JsonSerializable => $value->jsonSerialize(),
                $value instanceof Arrayable => $value->toArray(),
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
