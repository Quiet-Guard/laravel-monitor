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
        {--name= : Optional label for the backup (printable ASCII, no slashes, up to 255 characters)}
        {--keep= : After a successful upload, keep only the N newest backups OF THE SAME TYPE and delete the rest}
        {--exclude-table=* : With --database, keep this table\'s structure but leave its rows out of the backup (repeatable)}';

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

        // Avant le réseau aussi : une exclusion mal écrite doit arrêter la
        // commande, jamais être ignorée. Ce qu'on exclut, c'est ce qu'on a
        // promis de ne pas garder, et une sauvegarde qui l'emporte quand même
        // sans rien dire est le pire résultat possible de cette option.
        try {
            $this->excludedTables();
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

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

            $this->rotate($transport, $type);

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
            // copy() returns false rather than throwing, and a backup that
            // silently did not happen is the one failure this command may not
            // have. (Known limit, worth stating: in WAL mode the -wal sidecar
            // is not copied, so a very recent commit can be missing. VACUUM
            // INTO would fix that and is the next step here.)
            if (! copy($db['database'], $target)) {
                @unlink($target);

                throw new \RuntimeException("Could not copy the sqlite database at {$db['database']}.");
            }

            try {
                $this->emptySqliteTables($target, $this->excludedTables());
            } catch (Throwable $e) {
                @unlink($target);

                throw $e;
            }

            return $target;
        }

        $excluded = $this->excludedTables();

        $processes = match ($db['driver'] ?? null) {
            'mysql', 'mariadb' => $this->mysqlDumps($db, $excluded),
            // pg_dump sait le faire en une passe : --exclude-table-data garde
            // la définition de la table et ne copie pas ses lignes.
            'pgsql' => [new Process(
                [
                    'pg_dump', '-h', (string) $db['host'], '-p', (string) $db['port'], '-U', (string) $db['username'],
                    ...array_map(static fn (string $table): string => '--exclude-table-data='.$table, $excluded),
                    $db['database'],
                ],
                env: ['PGPASSWORD' => (string) $db['password']],
            )],
            default => throw new \RuntimeException("Unsupported database driver for backup: {$db['driver']}."),
        };

        $out = fopen($target, 'wb');
        $short = false;
        $failed = null;

        try {
            foreach ($processes as $process) {
                $process->setTimeout(600);

                // mysqldump writes to stdout and exits 0 whatever happened at
                // the other end of the pipe, so a full disk here used to
                // produce a short file, a successful process, an encrypted
                // upload and the word "uploaded". The customer found out at
                // restore time.
                $process->run(function ($type, $buffer) use ($out, &$short) {
                    if ($type !== Process::OUT) {
                        return;
                    }

                    $written = fwrite($out, $buffer);

                    if ($written === false || $written !== strlen($buffer)) {
                        $short = true;
                    }
                });

                if (! $process->isSuccessful()) {
                    $failed = $process;

                    break;
                }
            }
        } finally {
            $closed = fclose($out);
        }

        if ($failed !== null) {
            @unlink($target);

            throw new \RuntimeException('Database dump failed: '.$failed->getErrorOutput());
        }

        if ($short || ! $closed) {
            @unlink($target);

            throw new \RuntimeException(
                "Ran out of room writing the dump to {$target}. A database backup needs about twice the ".
                'dump size free in the temporary directory: once for the dump, once for the encrypted copy.'
            );
        }

        return $target;
    }

    /**
     * The tables whose rows stay out of a database backup.
     *
     * Their STRUCTURE is kept: a restored database missing a table breaks the
     * application that restores it, while an empty table is exactly what the
     * exclusion is for. The case that asked for it is a table of daily salts a
     * privacy policy promises to destroy within hours; a nightly backup taken
     * before that purge would keep yesterday's salt for the whole retention of
     * the vault, and the published promise would become false with nothing to
     * show it.
     *
     * Names are refused rather than escaped when they carry anything but
     * letters, digits, underscores, dollars and hyphens: they end up inside a
     * mysqldump `db.table` argument, a pg_dump pattern and a SQLite statement,
     * three grammars where a dot, a wildcard or a quote each mean something.
     *
     * @return list<string>
     */
    private function excludedTables(): array
    {
        $tables = array_values(array_unique(array_filter(
            array_map(static fn (mixed $table): string => trim((string) $table), (array) $this->option('exclude-table')),
            static fn (string $table): bool => $table !== '',
        )));

        if ($tables === []) {
            return [];
        }

        if (! $this->option('database')) {
            throw new \InvalidArgumentException('--exclude-table only applies to a --database backup.');
        }

        foreach ($tables as $table) {
            if (preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $table) !== 1) {
                throw new \InvalidArgumentException("Refusing to exclude \"{$table}\": a table name may only contain letters, digits, _, \$ and -.");
            }
        }

        return $tables;
    }

    /**
     * One mysqldump, or two when some tables keep their structure only.
     *
     * mysqldump has no flag for "schema of this table, no rows": --ignore-table
     * drops both. Hence the two passes, the same ones the hand-written backup
     * scripts this option replaces were doing: the structure of EVERY table,
     * then the rows of every table but the excluded ones. Each pass takes its
     * own consistent snapshot; the schema does not move during a backup, so
     * only the second one's consistency matters. Triggers come with the
     * structure and are skipped in the second pass, or a restore would create
     * them twice.
     *
     * @param  array<string, mixed>  $db
     * @param  list<string>  $excluded
     * @return list<Process>
     */
    private function mysqlDumps(array $db, array $excluded): array
    {
        if ($excluded === []) {
            return [$this->mysqldump($db, [])];
        }

        return [
            $this->mysqldump($db, ['--no-data']),
            $this->mysqldump($db, [
                '--no-create-info',
                '--skip-triggers',
                ...array_map(static fn (string $table): string => '--ignore-table='.$db['database'].'.'.$table, $excluded),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $db
     * @param  list<string>  $extra
     */
    private function mysqldump(array $db, array $extra): Process
    {
        // --single-transaction, ou mysqldump verrouille chaque table le
        // temps du dump : sur une base de production, c'est une panne
        // qu'on s'inflige avec une commande vendue comme une sauvegarde.
        // --no-tablespaces, ou MySQL 5.7.31+ exige le privilège PROCESS,
        // qu'un utilisateur applicatif correctement restreint n'a pas.
        // Le jeu de caractères, ou le client MariaDB retombe en utf8mb3 et
        // abîme tout caractère sur quatre octets.
        return new Process(
            [
                'mysqldump', '--single-transaction', '--no-tablespaces',
                '--default-character-set=utf8mb4',
                ...$extra,
                '-h', (string) $db['host'], '-P', (string) $db['port'],
                '-u', (string) $db['username'], $db['database'],
            ],
            env: ['MYSQL_PWD' => (string) $db['password']],
        );
    }

    /**
     * Empty the excluded tables of a SQLite COPY, never of the live file.
     *
     * The VACUUM is the half that matters. A DELETE only marks pages free, and
     * the rows stay readable in the file until something overwrites them, so
     * without it the archive would carry exactly what the exclusion promised
     * to leave out, merely harder to see.
     *
     * A table that does not exist is passed over, as mysqldump passes over an
     * --ignore-table naming nothing: there are no rows to leak.
     *
     * @param  list<string>  $tables
     */
    private function emptySqliteTables(string $path, array $tables): void
    {
        if ($tables === []) {
            return;
        }

        $pdo = new \PDO('sqlite:'.$path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $exists = $pdo->prepare("select 1 from sqlite_master where type = 'table' and name = ?");

        foreach ($tables as $table) {
            $exists->execute([$table]);
            $found = $exists->fetchColumn() !== false;

            // Un curseur resté ouvert fait refuser le VACUUM plus bas
            // (« SQL statements in progress »).
            $exists->closeCursor();

            if (! $found) {
                continue;
            }

            $pdo->exec('delete from "'.$table.'"');
        }

        $pdo->exec('vacuum');
        $pdo = null;
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

    /**
     * Keep the N newest of this type, delete the rest.
     *
     * The documentation recommends a nightly schedule and nothing ever removed
     * anything: monitor:prune sweeps eight categories on the server and backups
     * are not one of them. So the recommended setup filled the plan's allowance
     * and then failed every night, from the scheduler, where nobody is reading.
     *
     * Three decisions worth keeping:
     *
     * Only after a SUCCESSFUL upload. Deleting first would trade a full vault
     * for an empty one on the night the dump fails.
     *
     * Only the same TYPE. A nightly --database rotation has no business
     * deleting the folder backup somebody took by hand, and "the N newest" over
     * a mixed vault would do exactly that.
     *
     * A failed delete does not fail the command. The backup is uploaded and
     * safe; the vault is merely fuller than asked. Saying so is right, exiting
     * non-zero would tell a scheduler the backup did not happen.
     */
    private function rotate(Transport $transport, string $type): void
    {
        $keep = $this->option('keep');

        if ($keep === null) {
            return;
        }

        $keep = (int) $keep;

        if ($keep < 1) {
            $this->warn('--keep must be at least 1; nothing was deleted.');

            return;
        }

        $backups = $transport->listBackups();

        if ($backups === null) {
            $this->warn('Backup kept, but the vault could not be listed to rotate it: '.($transport->lastError() ?? 'unknown error'));

            return;
        }

        // The server returns them newest first; keep that order rather than
        // re-sorting on a date format the client would have to parse.
        $sameType = array_values(array_filter($backups, static fn (array $b): bool => ($b['type'] ?? null) === $type));
        $stale = array_slice($sameType, $keep);

        if ($stale === []) {
            return;
        }

        $deleted = 0;

        foreach ($stale as $backup) {
            if ($transport->deleteBackup((string) ($backup['id'] ?? ''))) {
                $deleted++;

                continue;
            }

            $this->warn('Could not delete '.($backup['id'] ?? '?').': '.($transport->lastError() ?? 'unknown error'));
        }

        if ($deleted > 0) {
            $this->info(sprintf('Rotated: %d older %s backup(s) deleted, %d kept.', $deleted, $type, $keep));
        }
    }
}
