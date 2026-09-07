<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use PharData;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Support\BackupCipher;
use Symfony\Component\Process\Process;
use Throwable;

class BackupCommand extends Command
{
    protected $signature = 'monitor:backup
        {--database : Back up the default database connection}
        {--path= : Back up a file or directory}
        {--name= : Optional label for the backup (printable ASCII, no slashes, up to 255 characters)}';

    protected $description = 'Create an encrypted backup (database dump or folder) and upload it to the zero-knowledge vault';

    public function handle(Transport $transport, BackupCipher $cipher): int
    {
        if (! (config('monitor.enabled') ?? false)) {
            $this->warn('Quiet Guard is disabled (MONITOR_ENABLED=false).');

            return self::SUCCESS;
        }

        // Avant le réseau : un `monitor:backup` sans option se faisait répondre
        // « le chiffrement n'est pas configuré » après un aller-retour, ce qui
        // nomme le mauvais problème.
        if (! $this->option('database') && blank($this->option('path'))) {
            $this->error('Specify what to back up: --database or --path=<file|dir>.');

            return self::FAILURE;
        }

        $key = $transport->getEncryptionKey();

        if (! is_array($key) || blank($key['public_key'] ?? null)) {
            // The server explains each of these differently and the client used
            // to render all of them as "enable encryption in the dashboard":
            // advice that fixes nothing when the URL is wrong, the key belongs
            // to another project, or the plan has no backups.
            $this->error($transport->lastError() !== null
                ? 'Could not fetch the encryption key: '.$transport->lastError()
                : 'Encryption is not configured for this team (enable it in the dashboard first).');

            return self::FAILURE;
        }

        try {
            [$type, $source] = $this->buildArchive();
        } catch (Throwable $e) {
            $this->error('Failed to build the backup archive: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($source === null) {
            $this->error('Specify what to back up: --database or --path=<file|dir>.');

            return self::FAILURE;
        }

        $encrypted = $this->tempFile('enc');

        try {
            $cipher->encryptFile($source, $encrypted, $key['public_key']);
            $result = $transport->uploadBackup($type, $this->option('name'), $encrypted);
        } finally {
            @unlink($source);
            @unlink($encrypted);
        }

        if (is_array($result)) {
            $this->info(sprintf('Backup uploaded (%s, %s).', $result['id'] ?? '?', $type));

            return self::SUCCESS;
        }

        // The server's own words when it gave any: it names the field it
        // refused or the quota that is full, which the generic line below
        // guesses at and gets wrong for every rejection it can explain.
        $this->error($transport->lastError() !== null
            ? 'Upload refused: '.$transport->lastError()
            : 'Upload failed (check MONITOR_URL / MONITOR_KEY and your storage quota).');

        return self::FAILURE;
    }

    /**
     * @return array{0: string, 1: ?string} [type, archivePath]
     */
    private function buildArchive(): array
    {
        if ($this->option('database')) {
            return ['database', $this->dumpDatabase()];
        }

        if ($path = $this->option('path')) {
            return ['files', $this->archivePath($path)];
        }

        return ['files', null];
    }

    private function dumpDatabase(): string
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");
        $target = $this->tempFile('sql');

        if (($db['driver'] ?? null) === 'sqlite') {
            copy($db['database'], $target);

            return $target;
        }

        $process = match ($db['driver'] ?? null) {
            'mysql', 'mariadb' => new Process(
                ['mysqldump', '-h', (string) $db['host'], '-P', (string) $db['port'], '-u', (string) $db['username'], $db['database']],
                env: ['MYSQL_PWD' => (string) $db['password']],
            ),
            'pgsql' => new Process(
                ['pg_dump', '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username'], $db['database']],
                env: ['PGPASSWORD' => (string) $db['password']],
            ),
            default => throw new \RuntimeException("Unsupported database driver for backup: {$db['driver']}."),
        };

        $process->setTimeout(600);
        $out = fopen($target, 'wb');
        $process->run(function ($type, $buffer) use ($out) {
            if ($type === Process::OUT) {
                fwrite($out, $buffer);
            }
        });
        fclose($out);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Database dump failed: '.$process->getErrorOutput());
        }

        return $target;
    }

    private function archivePath(string $path): string
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Path not found: {$path}");
        }

        $tar = $this->tempFile('tar');
        @unlink($tar); // PharData wants to create the file itself
        $phar = new PharData($tar);

        if (is_dir($path)) {
            $phar->buildFromDirectory($path);
        } else {
            $phar->addFile($path, basename($path));
        }

        return $tar;
    }

    private function tempFile(string $ext): string
    {
        return rtrim(sys_get_temp_dir(), '/').'/monitor-backup-'.bin2hex(random_bytes(8)).'.'.$ext;
    }
}
