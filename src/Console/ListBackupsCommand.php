<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use QuietGuard\LaravelMonitor\Http\Transport;

/**
 * What the vault holds for this project.
 *
 * The endpoint has existed since the vault shipped and no client could reach
 * it. A customer who lost the id printed at upload had to open the dashboard,
 * from a tool whose whole point is that a server can be operated from a shell,
 * and monitor:restore takes that id as its only required argument.
 */
class ListBackupsCommand extends Command
{
    protected $signature = 'monitor:backups';

    protected $description = 'List the encrypted backups this project holds in the vault';

    public function handle(Transport $transport): int
    {
        $backups = $transport->listBackups();

        if ($backups === null) {
            $this->error($transport->lastError() !== null
                ? 'Could not list the backups: '.$transport->lastError()
                : 'Could not list the backups (check MONITOR_URL / MONITOR_KEY).');

            return self::FAILURE;
        }

        if ($backups === []) {
            $this->info('No backups yet. Create one with: php artisan monitor:backup --database');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'type', 'name', 'size', 'created'],
            array_map(static fn (array $b): array => [
                $b['id'] ?? '?',
                $b['type'] ?? '?',
                $b['name'] ?? '—',
                self::humanBytes((int) ($b['size_bytes'] ?? 0)),
                $b['created_at'] ?? '?',
            ], $backups),
        );

        $this->line('Restore one with: php artisan monitor:restore <id> --output=<file>');

        return self::SUCCESS;
    }

    /** Base 1000, so a 5 GB allowance reads as 5 GB, as everywhere else in this product. */
    private static function humanBytes(int $bytes): string
    {
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $i => $unit) {
            if ($bytes < 1000 ** ($i + 1) || $unit === 'TB') {
                return round($bytes / (1000 ** $i), $i === 0 ? 0 : 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }
}
