<?php

namespace QuietGuard\LaravelMonitor\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use QuietGuard\LaravelMonitor\Http\Transport;
use Throwable;

/**
 * Sends what the recorder wrote: once a minute, every window file two windows
 * old or more, merged into ONE summary and ONE POST.
 *
 * No lock is held, ever. A file is claimed by renaming it (one rename wins),
 * the claimed windows become one batch file named after its batch id, and that
 * batch is what is posted and, after a timeout, a 429 or a 5xx, posted again
 * with the same id: the server keeps the ids it received, so a retry never
 * counts twice.
 *
 * The windows are minutes shifted by the instance's own offset (5 to 45 s),
 * drawn from its host name: the first writer of a window runs at that second
 * of the wall-clock minute, and so do the server's arrivals, spread across the
 * minute rather than piled on its first second.
 */
final class PerformanceFlusher
{
    public const VERSION = 1;

    /** A window is sent from two windows after it on: nobody can still write it. */
    public const SEND_AFTER_WINDOWS = 2;

    /** A claim older than this belongs to a process that died. */
    public const STALE_CLAIM_SECONDS = 120;

    /** Anything older is deleted unsent. */
    public const MAX_AGE_SECONDS = 900;

    /** Past this, writers stop until the next pass. */
    public const DIRECTORY_CAP_BYTES = 67_108_864;

    public const PAUSE_SECONDS = 3600;

    public const PAUSE_MARKER = 'paused';

    public const MAX_BODY_BYTES = 262_144;

    public const MAX_ROUTES = 200;

    public const MAX_QUERIES = 100;

    public const MAX_HOSTS = 50;

    public const MAX_JOBS = 100;

    /** Keys held in memory while reading, per kind: a file of distinct rows cannot exhaust memory. */
    public const MAX_KEYS_WHILE_READING = 1000;

    public const OTHER_ROUTE = '~other';

    /** @var array<string, bool> */
    private array $livewireNames = [];

    public function __construct(
        private readonly Application $app,
        private readonly Transport $transport,
        private readonly string $dir,
        private readonly string $instance,
        private readonly int $offset,
        private readonly string $environment,
        private readonly ?string $release,
    ) {}

    /** Twelve characters of the host name's sha1: never the name itself. */
    public static function instanceId(): string
    {
        return substr(sha1((string) gethostname()), 0, 12);
    }

    public static function offsetFor(string $instance): int
    {
        return 5 + (crc32($instance) % 41);
    }

    public static function windowAt(int $timestamp, int $offset): int
    {
        return intdiv($timestamp - $offset, 60);
    }

    public static function windowFile(int $window): string
    {
        return gmdate('YmdHi', $window * 60).'.ndjson';
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * The janitor, the retries, then the windows due. Never throws, and starts
     * no POST once $budget seconds are spent.
     */
    public function flushDue(float $budget = 3.0): void
    {
        $deadline = microtime(true) + $budget;

        try {
            if (! is_dir($this->dir)) {
                return;
            }

            if (! is_writable($this->dir)) {
                $this->warnOnce('unwritable', 'Quiet Guard: the performance directory is not writable, measurement is off', ['path' => $this->dir]);

                return;
            }

            $now = Carbon::now()->getTimestamp();
            $paused = @filemtime($this->dir.'/'.self::PAUSE_MARKER);

            if ($paused !== false && $paused > $now) {
                return;
            }

            if ($paused !== false) {
                @unlink($this->dir.'/'.self::PAUSE_MARKER);
            }

            $this->janitor($now);

            foreach ($this->pendingBatches() as $batch) {
                if (microtime(true) >= $deadline) {
                    return;
                }

                if (! $this->send($batch)) {
                    return;
                }
            }

            if (microtime(true) < $deadline && ($batch = $this->claimDueWindows($now)) !== null) {
                $this->send($batch);
            }
        } catch (Throwable) {
            // The host application never pays for a failed flush.
        }
    }

    /**
     * Old files go unsent, dead claims are released, and a directory past its
     * cap stops the writers until the next pass.
     */
    private function janitor(int $now): void
    {
        $total = 0;

        foreach ($this->entries() as $name) {
            $path = $this->dir.'/'.$name;
            $created = $this->createdAt($name);

            if (str_ends_with($name, '.sending') || str_ends_with($name, '.tmp')) {
                $mtime = @filemtime($path);

                if ($mtime !== false && $now - $mtime > self::STALE_CLAIM_SECONDS) {
                    $original = preg_replace('/\.\d+\.(sending|tmp)$/', '', $name);

                    if ($created === null || $now - $created > self::MAX_AGE_SECONDS || str_ends_with($name, '.tmp') || ! is_string($original)) {
                        @unlink($path);
                    } else {
                        @rename($path, $this->dir.'/'.$original);
                        $total += (int) @filesize($this->dir.'/'.$original);
                    }

                    continue;
                }
            } elseif ($created !== null && $now - $created > self::MAX_AGE_SECONDS) {
                @unlink($path);

                continue;
            }

            $total += (int) @filesize($path);
        }

        if ($total > self::DIRECTORY_CAP_BYTES) {
            @touch($this->dir.'/'.PerformanceRecorder::STOP_MARKER, $now + 60);
            // Counted once per pass: the reader sees one loss.
            @file_put_contents($this->dir.'/'.self::windowFile(self::windowAt($now, $this->offset)), PerformanceRecorder::OVERFLOW_LINE, FILE_APPEND | LOCK_EX);
        }
    }

    /** @return list<string> batch files waiting for a retry, oldest first */
    private function pendingBatches(): array
    {
        $batches = array_values(array_filter($this->entries(), fn (string $name): bool => preg_match('/^batch-\d+-[0-9a-f]{40}\.json$/', $name) === 1));
        sort($batches);

        return array_map(fn (string $name): string => $this->dir.'/'.$name, $batches);
    }

    /**
     * Every window two behind or more, claimed by rename and merged into one
     * batch file. Null when nothing is due, or another process won them all.
     */
    private function claimDueWindows(int $now): ?string
    {
        $current = self::windowAt($now, $this->offset);
        $claimed = [];
        $pid = getmypid();

        foreach ($this->entries() as $name) {
            if (preg_match('/^(\d{12})\.ndjson$/', $name, $match) !== 1) {
                continue;
            }

            $window = $this->windowOf($match[1]);

            if ($window === null || $window > $current - self::SEND_AFTER_WINDOWS) {
                continue;
            }

            $claim = $this->dir.'/'.$name.'.'.$pid.'.sending';

            if (@rename($this->dir.'/'.$name, $claim)) {
                @touch($claim);
                $claimed[$match[1]] = $claim;
            }
        }

        if ($claimed === []) {
            return null;
        }

        ksort($claimed);
        $payload = $this->aggregate($claimed);
        $batch = $payload['batch'];
        $file = $this->dir.'/batch-'.$now.'-'.$batch.'.json';
        $tmp = $file.'.'.$pid.'.tmp';

        if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) === false || ! @rename($tmp, $file)) {
            @unlink($tmp);

            return null;
        }

        foreach ($claimed as $claim) {
            @unlink($claim);
        }

        return $file;
    }

    /**
     * POST one batch, claimed by rename for the time of the call. False when
     * the server should not be asked again on this pass.
     */
    private function send(string $file): bool
    {
        $claim = $file.'.'.getmypid().'.sending';

        if (! @rename($file, $claim)) {
            return true;
        }

        @touch($claim);
        $payload = json_decode((string) @file_get_contents($claim), true);

        if (! is_array($payload)) {
            @unlink($claim);

            return true;
        }

        $status = $this->transport->sendPerformance($payload);

        // Kept for the next pass: the network, our 5xx, our 429. The janitor
        // drops it once it is fifteen minutes old.
        if ($status === null || $status === 429 || $status >= 500) {
            @rename($claim, $file);

            return false;
        }

        @unlink($claim);

        if ($status === 402 || $status === 403) {
            $until = Carbon::now()->getTimestamp() + self::PAUSE_SECONDS;
            @touch($this->dir.'/'.self::PAUSE_MARKER, $until);
            @touch($this->dir.'/'.PerformanceRecorder::STOP_MARKER, $until);
            $this->warn('Quiet Guard: performance measurement paused for an hour', ['status' => $status, 'error' => $this->transport->lastError()]);

            return false;
        }

        return true;
    }

    /**
     * Every line of the claimed windows, merged.
     *
     * @param  array<string, string>  $claimed  window name => claimed path
     * @return array<string, mixed>
     */
    private function aggregate(array $claimed): array
    {
        $routes = $queries = $hosts = $jobs = [];
        $dropped = 0;

        foreach ($claimed as $path) {
            $handle = @fopen($path, 'rb');

            if ($handle === false) {
                continue;
            }

            while (($raw = fgets($handle)) !== false) {
                $line = json_decode($raw, true);

                if (! is_array($line) || ($line['v'] ?? null) !== PerformanceRecorder::LINE_VERSION) {
                    $dropped++;

                    continue;
                }

                $type = $line['type'] ?? null;

                if ($type === 'overflow') {
                    $dropped++;
                } elseif ($type === 'request') {
                    $origin = $this->addRoute($routes, $line);
                    $this->addCosts($queries, $hosts, $line, $origin);
                } elseif ($type === 'job' && is_string($line['job'] ?? null)) {
                    $this->addJob($jobs, $line);
                    $this->addCosts($queries, $hosts, $line, $line['job']);
                } else {
                    $dropped++;
                }
            }

            fclose($handle);
        }

        $windows = array_keys($claimed);

        $payload = [
            'v' => self::VERSION,
            'minute' => gmdate('Y-m-d\TH:i:00+00:00', (int) $this->windowOf((string) $windows[0]) * 60 + $this->offset),
            'environment' => $this->environment,
            'release' => $this->release,
            'instance' => $this->instance,
            'batch' => sha1(implode('|', [$this->instance, $this->environment, realpath($this->dir) ?: $this->dir, ...$windows])),
            'dropped' => $dropped,
            'routes' => self::top($routes, self::MAX_ROUTES, fn (array $row): float => $row['duration_sum_ms']),
            'queries' => self::topQueries($queries),
            'hosts' => self::top($hosts, self::MAX_HOSTS, fn (array $row): float => $row['duration_sum_ms']),
            'jobs' => self::top($jobs, self::MAX_JOBS, fn (array $row): float => $row['duration_sum_ms']),
        ];

        return self::fitBody($payload);
    }

    /**
     * @param  array<string, array<string, mixed>>  $routes
     * @param  array<string, mixed>  $line
     * @return string the origin its queries are filed under
     */
    private function addRoute(array &$routes, array $line): string
    {
        $method = is_string($line['method'] ?? null) ? substr($line['method'], 0, 10) : 'OTHER';
        $route = is_string($line['route'] ?? null) ? $line['route'] : PerformanceRecorder::UNMATCHED;

        if ($route === PerformanceRecorder::LIVEWIRE_ROUTE && is_string($line['livewire'] ?? null) && $this->livewireExists($line['livewire'])) {
            $route .= ':'.$line['livewire'];
        }

        $key = $method.' '.$route;

        if (! isset($routes[$key]) && count($routes) >= self::MAX_KEYS_WHILE_READING) {
            $key = $method.' '.self::OTHER_ROUTE;
            $route = self::OTHER_ROUTE;
        }

        $ms = (float) ($line['ms'] ?? 0);
        $row = $routes[$key] ?? [
            'method' => $method, 'route' => $route, 'count' => 0, 'errors' => 0,
            'duration_sum_ms' => 0.0, 'duration_max_ms' => 0.0,
            'histogram' => array_fill(0, count(PerformanceRecorder::HISTOGRAM), 0),
            'db_count_sum' => 0, 'db_ms_sum' => 0.0,
        ];

        $row['count']++;
        $row['errors'] += (int) ($line['status'] ?? 0) >= 500 ? 1 : 0;
        $row['duration_sum_ms'] += $ms;
        $row['duration_max_ms'] = max($row['duration_max_ms'], $ms);
        $row['histogram'][PerformanceRecorder::bucket($ms)]++;
        $row['db_count_sum'] += (int) ($line['db_count'] ?? 0);
        $row['db_ms_sum'] += (float) ($line['db_ms'] ?? 0);
        $routes[$key] = $row;

        return $key;
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobs
     * @param  array<string, mixed>  $line
     */
    private function addJob(array &$jobs, array $line): void
    {
        $queue = is_string($line['queue'] ?? null) ? $line['queue'] : 'default';
        $key = $line['job'].'|'.$queue;

        if (! isset($jobs[$key]) && count($jobs) >= self::MAX_KEYS_WHILE_READING) {
            return;
        }

        $ms = (float) ($line['ms'] ?? 0);
        $row = $jobs[$key] ?? [
            'job' => $line['job'], 'queue' => $queue, 'count' => 0, 'failed' => 0,
            'duration_sum_ms' => 0.0, 'duration_max_ms' => 0.0,
            'histogram' => array_fill(0, count(PerformanceRecorder::HISTOGRAM), 0),
        ];

        $row['count']++;
        $row['failed'] += ($line['failed'] ?? false) === true ? 1 : 0;
        $row['duration_sum_ms'] += $ms;
        $row['duration_max_ms'] = max($row['duration_max_ms'], $ms);
        $row['histogram'][PerformanceRecorder::bucket($ms)]++;
        $jobs[$key] = $row;
    }

    /**
     * @param  array<string, array<string, mixed>>  $queries
     * @param  array<string, array<string, mixed>>  $hosts
     * @param  array<string, mixed>  $line
     */
    private function addCosts(array &$queries, array &$hosts, array $line, string $origin): void
    {
        foreach ((array) ($line['queries'] ?? []) as $query) {
            if (! is_array($query) || ! is_string($query['sql'] ?? null)) {
                continue;
            }

            $key = $query['sql']."\0".$origin;

            if (! isset($queries[$key]) && count($queries) >= self::MAX_KEYS_WHILE_READING) {
                continue;
            }

            $row = $queries[$key] ?? ['sql' => $query['sql'], 'origin' => $origin, 'count' => 0, 'duration_sum_ms' => 0.0, 'duration_max_ms' => 0.0, 'slow_count' => 0, 'n_plus_one_requests' => 0];
            $row['count'] += (int) ($query['count'] ?? 0);
            $row['duration_sum_ms'] += (float) ($query['ms'] ?? 0);
            $row['duration_max_ms'] = max($row['duration_max_ms'], (float) ($query['max'] ?? 0));
            $row['slow_count'] += (int) ($query['slow'] ?? 0);
            $row['n_plus_one_requests'] += ($query['n1'] ?? false) === true ? 1 : 0;
            $queries[$key] = $row;
        }

        foreach ((array) ($line['hosts'] ?? []) as $host) {
            if (! is_array($host) || ! is_string($host['host'] ?? null)) {
                continue;
            }

            $key = $host['host'];

            if (! isset($hosts[$key]) && count($hosts) >= self::MAX_KEYS_WHILE_READING) {
                continue;
            }

            $row = $hosts[$key] ?? ['host' => $key, 'count' => 0, 'failed' => 0, 'duration_sum_ms' => 0.0, 'duration_max_ms' => 0.0];
            $row['count'] += (int) ($host['count'] ?? 0);
            $row['failed'] += (int) ($host['failed'] ?? 0);
            $row['duration_sum_ms'] += (float) ($host['ms'] ?? 0);
            $row['duration_max_ms'] = max($row['duration_max_ms'], (float) ($host['max'] ?? 0));
            $hosts[$key] = $row;
        }
    }

    /**
     * A name the application really registers: a visitor wrote it.
     */
    private function livewireExists(string $name): bool
    {
        if (preg_match(PerformanceRecorder::LIVEWIRE_NAME, $name) !== 1) {
            return false;
        }

        if (! array_key_exists($name, $this->livewireNames)) {
            try {
                $this->livewireNames[$name] = $this->app->bound('livewire') && (bool) $this->app->make('livewire')->exists($name);
            } catch (Throwable) {
                $this->livewireNames[$name] = false;
            }
        }

        return $this->livewireNames[$name];
    }

    /**
     * The costliest rows, durations as whole milliseconds.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): float  $cost
     * @return list<array<string, mixed>>
     */
    private static function top(array $rows, int $limit, callable $cost): array
    {
        uasort($rows, fn (array $a, array $b): int => $cost($b) <=> $cost($a));

        return array_map(self::rounded(...), array_values(array_slice($rows, 0, $limit)));
    }

    /**
     * The statements with an N+1 or a slow run first, then the costliest.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function topQueries(array $rows): array
    {
        uasort($rows, fn (array $a, array $b): int => [($b['n_plus_one_requests'] + $b['slow_count']) > 0, $b['duration_sum_ms']] <=> [($a['n_plus_one_requests'] + $a['slow_count']) > 0, $a['duration_sum_ms']]);

        return array_map(self::rounded(...), array_values(array_slice($rows, 0, self::MAX_QUERIES)));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function rounded(array $row): array
    {
        foreach (['duration_sum_ms', 'duration_max_ms', 'db_ms_sum'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = (int) round($row[$field]);
            }
        }

        return $row;
    }

    /**
     * Under the server's 256 KB, whatever the content: the cheapest rows go
     * first. The caps keep a real summary far below it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function fitBody(array $payload): array
    {
        while (strlen((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) > self::MAX_BODY_BYTES) {
            $largest = null;

            foreach (['queries', 'routes', 'jobs', 'hosts'] as $kind) {
                if ($payload[$kind] !== [] && ($largest === null || count($payload[$kind]) > count($payload[$largest]))) {
                    $largest = $kind;
                }
            }

            if ($largest === null) {
                break;
            }

            $removed = (int) ceil(count($payload[$largest]) / 4);
            $payload[$largest] = array_slice($payload[$largest], 0, count($payload[$largest]) - $removed);
            $payload['dropped'] += $removed;
        }

        return $payload;
    }

    /** @return list<string> */
    private function entries(): array
    {
        $entries = @scandir($this->dir);

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }

    /** When a file's content was born, read from its name: a rename keeps the mtime. */
    private function createdAt(string $name): ?int
    {
        if (preg_match('/^(\d{12})\.ndjson/', $name, $match) === 1) {
            $window = $this->windowOf($match[1]);

            return $window === null ? null : $window * 60 + $this->offset;
        }

        if (preg_match('/^batch-(\d+)-/', $name, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function windowOf(string $name): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!YmdHi', $name, new DateTimeZone('UTC'));

        return $date === false ? null : intdiv($date->getTimestamp(), 60);
    }

    /**
     * One warning an hour at most, through the application's cache, read only
     * on this failing path and outside any web request's hot path.
     *
     * @param  array<string, mixed>  $context
     */
    private function warnOnce(string $what, string $message, array $context): void
    {
        try {
            if ($this->app->make(Cache::class)->add('quiet-guard:performance:'.$what.':'.sha1($this->dir), true, 3600)) {
                $this->warn($message, $context);
            }
        } catch (Throwable) {
        }
    }

    /** @param  array<string, mixed>  $context */
    private function warn(string $message, array $context): void
    {
        try {
            $this->app->make('log')->warning($message, $context + [Transport::INTERNAL => true]);
        } catch (Throwable) {
        }
    }
}
