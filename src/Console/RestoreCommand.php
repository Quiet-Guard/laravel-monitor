<?php

namespace LaBoiteACode\LaravelMonitor\Console;

use Illuminate\Console\Command;
use LaBoiteACode\LaravelMonitor\Http\Transport;
use LaBoiteACode\LaravelMonitor\Support\BackupCipher;
use Throwable;

class RestoreCommand extends Command
{
    protected $signature = 'monitor:restore
        {id : The backup id returned at upload time}
        {--output= : Where to write the decrypted backup}
        {--passphrase= : Team passphrase (prompted if omitted)}';

    protected $description = 'Download an encrypted backup and decrypt it locally with the team passphrase';

    public function handle(Transport $transport, BackupCipher $cipher): int
    {
        $key = $transport->getEncryptionKey();

        if (! is_array($key) || blank($key['public_key'] ?? null) || blank($key['private_key_wrapped'] ?? null)) {
            $this->error('Encryption is not configured for this team.');

            return self::FAILURE;
        }

        $passphrase = $this->option('passphrase') ?: $this->secret('Team passphrase');

        try {
            $secret = $cipher->unwrapPrivateKey($key['private_key_wrapped'], $key['kdf_salt'], (string) $passphrase);
        } catch (Throwable) {
            $this->error('Wrong passphrase — cannot unwrap the private key.');

            return self::FAILURE;
        }

        $id = $this->argument('id');
        $blob = rtrim(sys_get_temp_dir(), '/').'/monitor-restore-'.bin2hex(random_bytes(8)).'.enc';

        if (! $transport->downloadBackup($id, $blob)) {
            @unlink($blob);
            $this->error('Failed to download the backup (check the id and your credentials).');

            return self::FAILURE;
        }

        $output = $this->option('output') ?: base_path("restored-{$id}.out");

        try {
            $cipher->decryptFile($blob, $output, $key['public_key'], $secret);
        } catch (Throwable $e) {
            $this->error('Decryption failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($blob);
        }

        $this->info("Backup restored to {$output}.");

        return self::SUCCESS;
    }
}
