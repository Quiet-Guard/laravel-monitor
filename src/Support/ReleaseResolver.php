<?php

namespace QuietGuard\LaravelMonitor\Support;

/**
 * Finds the identifier of the deployed version without the operator typing it.
 *
 * The resolution order is fixed: the configured value (`MONITOR_RELEASE`) when
 * filled, then a `REVISION` file at the base path (Capistrano, Deployer and
 * most hand-written deploy scripts write one), then the checkout's own
 * `.git/HEAD`, then nothing. Every file read is guarded and a failure only
 * moves on to the next candidate: an exception storm is the worst moment to
 * raise a second exception about a missing file.
 *
 * The result is memoised per base path for the life of the process, because a
 * storm of a thousand exceptions must not read the disk a thousand times, and
 * a deploy that changes the checkout also restarts the workers.
 */
final class ReleaseResolver
{
    /**
     * The shape the server matches against synced commits, and the only shape
     * accepted from a git ref: a ref can hold anything, and a value that is not
     * a SHA there is a symbolic ref we chose not to follow, never a release.
     */
    private const SHA_PATTERN = '/^[0-9a-f]{7,64}$/i';

    /** The longest value the payload carries (`context.release`). */
    private const MAX_LENGTH = 255;

    /** More than a ref file or a HEAD has any reason to hold. */
    private const MAX_FILE_BYTES = 4096;

    /** packed-refs lists every ref of a large repository; a generous bound. */
    private const MAX_PACKED_REFS_BYTES = 1048576;

    /** @var array<string, string|null> */
    private static array $detected = [];

    /**
     * The configured value always wins; auto-detection fills in only an empty one.
     */
    public static function resolve(?string $configured, string $basePath): ?string
    {
        $configured = trim((string) $configured);

        if ($configured !== '') {
            return $configured;
        }

        return self::detect($basePath);
    }

    /**
     * Forget every memoised detection (tests, or a long-running process that
     * knows the checkout moved underneath it).
     */
    public static function flush(): void
    {
        self::$detected = [];
    }

    private static function detect(string $basePath): ?string
    {
        $basePath = rtrim($basePath, '/\\');

        if (array_key_exists($basePath, self::$detected)) {
            return self::$detected[$basePath];
        }

        return self::$detected[$basePath] = self::fromRevisionFile($basePath) ?? self::fromGitHead($basePath);
    }

    /**
     * A REVISION file may hold a tag as well as a SHA: any single-line value is
     * accepted, since a label is what the operator wrote there on purpose.
     */
    private static function fromRevisionFile(string $basePath): ?string
    {
        $line = self::firstLine($basePath.'/REVISION', self::MAX_FILE_BYTES);

        if ($line === null || $line === '' || mb_strlen($line) > self::MAX_LENGTH) {
            return null;
        }

        return $line;
    }

    /**
     * A detached HEAD holds the SHA itself; a branch HEAD points at
     * `refs/heads/<branch>`, which lives as a loose file until git packs it.
     */
    private static function fromGitHead(string $basePath): ?string
    {
        $head = self::firstLine($basePath.'/.git/HEAD', self::MAX_FILE_BYTES);

        if ($head === null || $head === '') {
            return null;
        }

        if (self::isSha($head)) {
            return $head;
        }

        if (! str_starts_with($head, 'ref:')) {
            return null;
        }

        $ref = trim(substr($head, 4));

        // A ref name is a relative path under .git; refuse anything that could
        // climb out of it, however unlikely a hand-edited HEAD is.
        if ($ref === '' || str_contains($ref, '..') || str_starts_with($ref, '/')) {
            return null;
        }

        $loose = self::firstLine($basePath.'/.git/'.$ref, self::MAX_FILE_BYTES);

        if ($loose !== null && self::isSha($loose)) {
            return $loose;
        }

        return self::fromPackedRefs($basePath, $ref);
    }

    private static function fromPackedRefs(string $basePath, string $ref): ?string
    {
        $contents = self::read($basePath.'/.git/packed-refs', self::MAX_PACKED_REFS_BYTES);

        if ($contents === null) {
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $contents) ?: [] as $line) {
            $line = trim($line);

            // Comments (`# pack-refs with: peeled`) and peeled tag lines (`^<sha>`).
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);

            if (count($parts) === 2 && $parts[1] === $ref && self::isSha($parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }

    private static function firstLine(string $path, int $maxBytes): ?string
    {
        $contents = self::read($path, $maxBytes);

        if ($contents === null) {
            return null;
        }

        $line = strtok($contents, "\r\n");

        return $line === false ? '' : trim($line);
    }

    /**
     * Guarded read: a missing, unreadable or oversized file is a null, never a
     * warning and never an exception.
     */
    private static function read(string $path, int $maxBytes): ?string
    {
        if (! @is_file($path) || ! @is_readable($path)) {
            return null;
        }

        $size = @filesize($path);

        if ($size === false || $size > $maxBytes) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private static function isSha(string $value): bool
    {
        return preg_match(self::SHA_PATTERN, $value) === 1;
    }
}
