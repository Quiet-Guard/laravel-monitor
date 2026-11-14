<?php

namespace QuietGuard\LaravelMonitor\Support;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use QuietGuard\Monitor\Support\SqlNormalizer;
use QuietGuard\Monitor\Support\ValueRedactor;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * What one HTTP request, or one queued job, cost: its duration, its SQL and
 * its outgoing HTTP calls, kept in memory while it runs and appended as ONE
 * line to the file of the current window when it ends.
 *
 * The rule this class exists to keep: nothing here may slow the host
 * application down. No SQL query, no cache call, no network: per query a few
 * array operations, and at the end a stat (the pause marker), a file_exists
 * (a new window?) and an append. Only the few remarkable queries of a request
 * are normalised, at the end. Every entry point swallows what it raises.
 *
 * Nothing personal is written: SQL is normalised then masked (no binding ever
 * leaves memory, only a crc32 of them to count distinct sets), the route is a
 * name or a URI pattern (never the path), a host is a host (never a URL).
 */
final class PerformanceRecorder
{
    /** Distinct raw SQL statements followed per request; beyond, only the totals move. */
    public const MAX_SQL_KEYS = 500;

    /** A raw statement longer than this is followed by its first bytes. */
    public const SQL_KEY_BYTES = 4096;

    /** Distinct hosts followed per request. */
    public const MAX_HOSTS = 50;

    public const MAX_LINE_QUERIES = 5;

    public const MAX_LINE_HOSTS = 5;

    /** The costliest statements always written, on top of the flagged ones. */
    public const COSTLIEST = 3;

    public const SLOW_QUERY_MS = 1000;

    public const N_PLUS_ONE_REPEATS = 10;

    public const N_PLUS_ONE_DISTINCT = 5;

    /** A window file past this size is no longer appended to. */
    public const MAX_FILE_BYTES = 8_388_608;

    public const LINE_VERSION = 1;

    public const OVERFLOW_LINE = '{"v":1,"type":"overflow"}'."\n";

    public const UNMATCHED = '~unmatched';

    public const LIVEWIRE_ROUTE = 'livewire.update';

    /** What an IP address is called in a line: the same token the server uses. */
    public const IP_HOST = 'adresse-ip';

    public const LIVEWIRE_NAME = '/^[a-z0-9._:-]{1,100}\z/';

    public const SECRET_SEGMENT = '/^[A-Za-z0-9_-]{20,}$/';

    /**
     * The upper bound of each histogram bucket, in ms, inclusive: 1000 ms falls
     * in "≤ 1000". The last one is open.
     *
     * @var list<int|float>
     */
    public const HISTOGRAM = [5, 10, 20, 30, 50, 75, 100, 150, 200, 300, 500, 750, 1000, 1500, 2000, 3000, 5000, 7500, 10000, 20000, INF];

    /** Writers stop while this file's mtime is in the future (pause or full directory). */
    public const STOP_MARKER = 'stop';

    /**
     * Per raw SQL: [count, ms, max, slow, distinct crc32 set, first bindings].
     *
     * @var array<string, array{0: int, 1: float, 2: float, 3: int, 4: array<int, true>, 5: array<mixed>|null}>
     */
    private array $queries = [];

    private int $queryCount = 0;

    private float $queryMs = 0.0;

    /** @var array<string, array{0: int, 1: int, 2: float, 3: float}> host => [count, failed, ms, max] */
    private array $hosts = [];

    /** @var array<int, float> spl_object_id(request) => microtime */
    private array $httpStarts = [];

    /** Set by Octane's RequestReceived: the worker's LARAVEL_START is its own boot. */
    private ?float $startedAt = null;

    /** @var array{method: string, route: string, status: int, ms: float, livewire: ?string}|null */
    private ?array $request = null;

    /** @var array{job: string, queue: string, start: float}|null */
    private ?array $job = null;

    private bool $jobOpen = false;

    /** The directory could not be written: measurement off for this process. */
    private bool $off = false;

    private ?ValueRedactor $redactor = null;

    /** @var array<string, true>|null */
    private ?array $excludedTables = null;

    private static bool $firstRequest = true;

    /**
     * @param  list<string>  $ignorePaths
     * @param  Closure(): void  $onNewWindow  the first writer of a window flushes the past ones
     */
    public function __construct(
        private readonly Application $app,
        private readonly string $dir,
        private readonly int $offset,
        private readonly ?string $monitorHost,
        private readonly array $ignorePaths,
        private readonly Closure $onNewWindow,
    ) {}

    /**
     * The master switch, a key, MONITOR_ENVIRONMENTS, and the measurement's
     * own switch and environments (production by default, like heartbeats).
     */
    public static function active(Application $app): bool
    {
        $config = (array) $app['config']->get('monitor', []);
        $performance = (array) ($config['performance'] ?? []);

        if (! ($config['enabled'] ?? false) || blank($config['key'] ?? null) || ! filter_var($performance['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $environment = $app->environment();

        foreach ([$config['environments'] ?? [], $performance['environments'] ?? ['production']] as $allowed) {
            $allowed = (array) $allowed;

            if ($allowed !== [] && ! in_array($environment, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    public static function bucket(float $ms): int
    {
        foreach (self::HISTOGRAM as $i => $bound) {
            if ($ms <= $bound) {
                return $i;
            }
        }

        return count(self::HISTOGRAM) - 1;
    }

    /**
     * A route name, otherwise its URI pattern with any long opaque segment
     * replaced; never the path. `route:cache` names every unnamed route
     * `generated::<random>`, which is no name.
     */
    public static function routeKey(?Route $route): string
    {
        if ($route === null) {
            return self::UNMATCHED;
        }

        $name = $route->getName();

        if (is_string($name) && $name !== '' && ! str_starts_with($name, 'generated::')) {
            return str_ends_with($name, self::LIVEWIRE_ROUTE) ? self::LIVEWIRE_ROUTE : $name;
        }

        $segments = array_map(
            fn (string $segment): string => preg_match(self::SECRET_SEGMENT, $segment) === 1 ? '{secret}' : $segment,
            explode('/', $route->uri()),
        );

        return implode('/', $segments);
    }

    public function onOctaneRequest(): void
    {
        $this->reset();
        $this->startedAt = microtime(true);
    }

    public function onQuery(QueryExecuted $event): void
    {
        try {
            $ms = (float) $event->time;
            $this->queryCount++;
            $this->queryMs += $ms;

            $sql = strlen($event->sql) > self::SQL_KEY_BYTES ? substr($event->sql, 0, self::SQL_KEY_BYTES) : $event->sql;
            $entry = $this->queries[$sql] ?? null;

            if ($entry === null) {
                if (count($this->queries) >= self::MAX_SQL_KEYS) {
                    return;
                }

                // The bindings are kept as a reference until a second run
                // asks for them, and only for a SELECT, the only N+1.
                $this->queries[$sql] = [1, $ms, $ms, $ms >= self::SLOW_QUERY_MS ? 1 : 0, [], self::isSelect($sql) ? $event->bindings : null, self::quotesIdentifiersDoubly($event)];

                return;
            }

            $entry[0]++;
            $entry[1] += $ms;
            $entry[2] = max($entry[2], $ms);
            $entry[3] += $ms >= self::SLOW_QUERY_MS ? 1 : 0;

            // Distinct sets of values are counted up to the threshold, then
            // nothing more is hashed.
            if ($entry[5] !== null && count($entry[4]) < self::N_PLUS_ONE_DISTINCT) {
                if ($entry[4] === []) {
                    $entry[4][self::fingerprint($entry[5])] = true;
                }

                $entry[4][self::fingerprint($event->bindings)] = true;
            }

            $this->queries[$sql] = $entry;
        } catch (Throwable) {
            // Measuring must never break a query.
        }
    }

    public function onRequestSending(RequestSending $event): void
    {
        try {
            if (count($this->httpStarts) < self::MAX_HOSTS * 2) {
                $this->httpStarts[spl_object_id($event->request)] = microtime(true);
            }
        } catch (Throwable) {
        }
    }

    public function onResponse(ResponseReceived $event): void
    {
        try {
            $seconds = $event->response->handlerStats()['total_time'] ?? null;
            $this->recordHost($event->request, is_numeric($seconds) && $seconds > 0 ? $seconds * 1000 : null, $event->response->status() >= 500);
        } catch (Throwable) {
        }
    }

    public function onConnectionFailed(ConnectionFailed $event): void
    {
        try {
            $this->recordHost($event->request, null, true);
        } catch (Throwable) {
        }
    }

    /**
     * The response is ready: what the visitor waited for is measured here,
     * and the line is written at terminating, after the response left.
     */
    public function onRequestHandled(Request $request, Response $response): void
    {
        try {
            $this->request = null;

            if ($this->ignored($request)) {
                return;
            }

            $route = $request->route();
            $key = self::routeKey($route instanceof Route ? $route : null);
            $start = $this->requestStart();

            $this->request = [
                'method' => strtoupper($request->getMethod()),
                'route' => $key,
                'status' => $response->getStatusCode(),
                'ms' => $start === null ? 0.0 : max(0.0, (microtime(true) - $start) * 1000),
                'livewire' => $key === self::LIVEWIRE_ROUTE ? self::livewireName($request) : null,
            ];
        } catch (Throwable) {
            $this->request = null;
        } finally {
            self::$firstRequest = false;
        }
    }

    public function onTerminating(): void
    {
        try {
            if ($this->request === null) {
                return;
            }

            $request = $this->request;
            $line = [
                'v' => self::LINE_VERSION,
                'type' => 'request',
                'method' => $request['method'],
                'route' => $request['route'],
                'status' => $request['status'],
                'ms' => round($request['ms'], 2),
            ];

            if ($request['livewire'] !== null) {
                $line['livewire'] = $request['livewire'];
            }

            $this->append($line + $this->costs());
        } catch (Throwable) {
            // Never the host application's problem.
        } finally {
            $this->reset();
        }
    }

    public function onJobProcessing(JobProcessing $event): void
    {
        try {
            $this->reset();

            // A sync job runs inside its caller: its cost is the caller's.
            if ($event->connectionName === 'sync') {
                return;
            }

            $payload = $event->job->payload();
            $class = $payload['data']['commandName'] ?? $payload['job'] ?? null;

            $this->jobOpen = true;
            $this->job = is_string($class) && ! str_starts_with(ltrim($class, '\\'), 'QuietGuard\\')
                ? ['job' => substr($class, 0, 255), 'queue' => self::queueName((string) $event->job->getQueue()), 'start' => microtime(true)]
                : null;
        } catch (Throwable) {
            $this->job = null;
        }
    }

    /**
     * JobProcessed, JobExceptionOccurred or JobFailed, whichever comes first
     * for the job that started: one line per attempt.
     */
    public function onJobEnded(bool $failed): void
    {
        try {
            if (! $this->jobOpen || $this->job === null) {
                return;
            }

            $this->append([
                'v' => self::LINE_VERSION,
                'type' => 'job',
                'job' => $this->job['job'],
                'queue' => $this->job['queue'],
                'failed' => $failed,
                'ms' => round(max(0.0, (microtime(true) - $this->job['start']) * 1000), 2),
            ] + $this->costs());
        } catch (Throwable) {
        } finally {
            $this->reset();
        }
    }

    /**
     * @internal What the recorder holds right now. For tests.
     *
     * @return array{queries: int, hosts: int}
     */
    public function sizes(): array
    {
        return ['queries' => count($this->queries), 'hosts' => count($this->hosts)];
    }

    public function reset(): void
    {
        $this->queries = [];
        $this->queryCount = 0;
        $this->queryMs = 0.0;
        $this->hosts = [];
        $this->httpStarts = [];
        $this->request = null;
        $this->job = null;
        $this->jobOpen = false;
        $this->startedAt = null;
    }

    /**
     * Whether this raw statement, with these counts, is an N+1: a SELECT run
     * ten times or more with five distinct sets of values at least, on a table
     * that is not the framework's own (the database cache store repeats the
     * same read on every page, a session read is one per request).
     *
     * @param  array{0: int, 1: float, 2: float, 3: int, 4: array<int, true>, 5: array<mixed>|null}  $entry
     */
    private function isNPlusOne(string $sql, array $entry): bool
    {
        return $entry[5] !== null
            && $entry[0] >= self::N_PLUS_ONE_REPEATS
            && count($entry[4]) >= self::N_PLUS_ONE_DISTINCT
            && ! isset($this->excludedTables()[self::fromTable($sql)]);
    }

    /**
     * The SQL, hosts and totals of the request or job, the remarkable
     * statements normalised and masked here, and only them.
     *
     * @return array<string, mixed>
     */
    private function costs(): array
    {
        $flagged = [];
        $costliest = [];

        foreach ($this->queries as $sql => $entry) {
            $nPlusOne = $this->isNPlusOne($sql, $entry);

            if ($nPlusOne || $entry[3] > 0) {
                $flagged[$sql] = $entry[1];
            }

            $costliest[$sql] = $entry[1];
        }

        arsort($flagged);
        arsort($costliest);

        $chosen = array_slice(array_keys($flagged + array_slice($costliest, 0, self::COSTLIEST, true)), 0, self::MAX_LINE_QUERIES);
        $queries = [];

        foreach ($chosen as $sql) {
            $entry = $this->queries[$sql];
            $doubleQuoted = (bool) ($entry[6] ?? false);
            $shape = $this->redactor()->redact(SqlNormalizer::normalize((string) $sql, $doubleQuoted));
            $existing = $queries[$shape] ?? ['sql' => $shape, 'qi' => $doubleQuoted, 'count' => 0, 'ms' => 0.0, 'max' => 0.0, 'slow' => 0, 'n1' => false];

            // Two raw statements of one shape (integers written in line) are
            // one statement to the reader.
            $queries[$shape] = [
                'sql' => $shape,
                'qi' => $doubleQuoted,
                'count' => $existing['count'] + $entry[0],
                'ms' => round($existing['ms'] + $entry[1], 2),
                'max' => round(max($existing['max'], $entry[2]), 2),
                'slow' => $existing['slow'] + $entry[3],
                'n1' => $existing['n1'] || $this->isNPlusOne((string) $sql, $entry),
            ];
        }

        $hosts = $this->hosts;
        uasort($hosts, fn (array $a, array $b): int => $b[2] <=> $a[2]);
        $lineHosts = [];

        foreach (array_slice($hosts, 0, self::MAX_LINE_HOSTS, true) as $host => [$count, $failed, $ms, $max]) {
            $lineHosts[] = ['host' => (string) $host, 'count' => $count, 'failed' => $failed, 'ms' => round($ms, 2), 'max' => round($max, 2)];
        }

        return [
            'db_count' => $this->queryCount,
            'db_ms' => round($this->queryMs, 2),
            'queries' => array_values($queries),
            'hosts' => $lineHosts,
        ];
    }

    /**
     * One line appended to the window's file. The first writer of a window
     * (the file did not exist) then runs the flusher, which sends the windows
     * that are two behind.
     *
     * @param  array<string, mixed>  $line
     */
    private function append(array $line): void
    {
        if ($this->off) {
            return;
        }

        $now = Carbon::now()->getTimestamp();
        $until = @filemtime($this->dir.'/'.self::STOP_MARKER);

        if ($until !== false && $until > $now) {
            return;
        }

        $path = $this->dir.'/'.PerformanceFlusher::windowFile(PerformanceFlusher::windowAt($now, $this->offset));
        $new = ! file_exists($path);

        if ($new && ! is_dir($this->dir) && ! @mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            $this->off = true;

            return;
        }

        $handle = @fopen($path, 'ab');

        if ($handle === false) {
            $this->off = true;

            return;
        }

        try {
            flock($handle, LOCK_EX);
            $size = (int) (fstat($handle)['size'] ?? 0);

            if ($size < self::MAX_FILE_BYTES) {
                $encoded = json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n";

                // The line that crosses the cap closes the file with the one
                // marker the reader counts as a loss; nobody writes after it.
                fwrite($handle, $size + strlen($encoded) >= self::MAX_FILE_BYTES ? $encoded.self::OVERFLOW_LINE : $encoded);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($new) {
            ($this->onNewWindow)();
        }
    }

    private function recordHost(object $request, ?float $ms, bool $failed): void
    {
        $id = spl_object_id($request);
        $started = $this->httpStarts[$id] ?? null;
        unset($this->httpStarts[$id]);

        $host = method_exists($request, 'url') ? parse_url((string) $request->url(), PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return;
        }

        $host = strtolower(trim($host, '[]'));

        if ($host === $this->monitorHost) {
            return;
        }

        // An address can be a person's (a webhook to a customer's own box):
        // it never leaves the application, whatever its form (127.1, 0x7f000001).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}\z/', $host) === 1) {
            $host = self::IP_HOST;
        }

        if (! isset($this->hosts[$host]) && count($this->hosts) >= self::MAX_HOSTS) {
            return;
        }

        $ms ??= $started === null ? 0.0 : (microtime(true) - $started) * 1000;
        [$count, $failures, $sum, $max] = $this->hosts[$host] ?? [0, 0, 0.0, 0.0];
        $this->hosts[$host] = [$count + 1, $failures + ($failed ? 1 : 0), $sum + $ms, max($max, $ms)];
    }

    /**
     * Octane's own start, otherwise LARAVEL_START on the first request of the
     * process (the bootstrap is part of the wait), otherwise when the kernel
     * started handling this request.
     */
    private function requestStart(): ?float
    {
        if ($this->startedAt !== null) {
            return $this->startedAt;
        }

        if (self::$firstRequest && defined('LARAVEL_START')) {
            return (float) LARAVEL_START;
        }

        try {
            $kernel = $this->app->make(HttpKernel::class);
            $started = method_exists($kernel, 'requestStartedAt') ? $kernel->requestStartedAt() : null;

            if ($started instanceof Carbon) {
                return (float) $started->format('U.u');
            }
        } catch (Throwable) {
        }

        $time = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_numeric($time) ? (float) $time : null;
    }

    private function ignored(Request $request): bool
    {
        $patterns = array_values(array_filter($this->ignorePaths, 'is_string'));

        return $patterns !== [] && $request->is(...$patterns);
    }

    /**
     * The first component of a Livewire update, read from the payload the
     * visitor wrote: nothing else is read from it, the name is cut to 100
     * characters and must look like a component name. The flusher then checks
     * it against the components the application really has.
     */
    private static function livewireName(Request $request): ?string
    {
        $snapshot = $request->json('components.0.snapshot');

        if (! is_string($snapshot) || strlen($snapshot) > 1_048_576) {
            return null;
        }

        $name = json_decode($snapshot, true)['memo']['name'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        $name = substr($name, 0, 100);

        return preg_match(self::LIVEWIRE_NAME, $name) === 1 ? $name : null;
    }

    private static function queueName(string $queue): string
    {
        if (filter_var($queue, FILTER_VALIDATE_URL) !== false) {
            $queue = basename((string) parse_url($queue, PHP_URL_PATH));
        }

        return substr($queue, 0, 100);
    }

    /** @var array<string, bool> per connection name */
    private static array $doubleQuotedConnections = [];

    /**
     * Whether this connection's grammar writes identifiers in double quotes
     * (Postgres, SQLite, SQL Server), read once per connection name.
     */
    private static function quotesIdentifiersDoubly(QueryExecuted $event): bool
    {
        return self::$doubleQuotedConnections[$event->connectionName] ??= in_array(
            $event->connection->getDriverName(),
            SqlNormalizer::DOUBLE_QUOTED_IDENTIFIER_DRIVERS,
            true,
        );
    }

    private static function isSelect(string $sql): bool
    {
        return strncasecmp(ltrim($sql, " \t\n\r("), 'select', 6) === 0;
    }

    /** The first table after FROM, without quotes nor schema. */
    private static function fromTable(string $sql): string
    {
        if (preg_match('/\bfrom\s+[`"\[]?(?:[A-Za-z0-9_$]+[`"\]]?\.[`"\[]?)?([A-Za-z0-9_$]+)/i', $sql, $match) !== 1) {
            return '';
        }

        return strtolower($match[1]);
    }

    /**
     * The tables the framework itself reads on every request or every job,
     * read from the configuration with the connection's prefix.
     *
     * @return array<string, true>
     */
    private function excludedTables(): array
    {
        if ($this->excludedTables !== null) {
            return $this->excludedTables;
        }

        $config = $this->app['config'];
        $tables = [
            $config->get('cache.stores.database.table', 'cache'),
            $config->get('cache.stores.database.lock_table', 'cache_locks'),
            $config->get('session.table', 'sessions'),
            $config->get('queue.connections.database.table', 'jobs'),
            $config->get('queue.batching.table', 'job_batches'),
            $config->get('queue.failed.table', 'failed_jobs'),
        ];

        $prefixes = array_filter([
            $config->get('database.connections.'.$config->get('database.default').'.prefix'),
            $config->get('database.connections.'.$config->get('cache.stores.database.connection').'.prefix'),
        ], fn ($prefix): bool => is_string($prefix) && $prefix !== '');

        $excluded = [];

        foreach (array_filter($tables, 'is_string') as $table) {
            $excluded[strtolower($table)] = true;

            foreach ($prefixes as $prefix) {
                $excluded[strtolower($prefix.$table)] = true;
            }
        }

        return $this->excludedTables = $excluded;
    }

    /** @param  array<mixed>  $bindings */
    private static function fingerprint(array $bindings): int
    {
        try {
            return crc32(serialize($bindings));
        } catch (Throwable) {
            // An unserialisable value counts as a distinct set.
            return random_int(0, PHP_INT_MAX);
        }
    }

    private function redactor(): ValueRedactor
    {
        return $this->redactor ??= new ValueRedactor(ValueRedactor::PATTERNS);
    }
}
