<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use QuietGuard\LaravelMonitor\Http\Transport;
use QuietGuard\LaravelMonitor\Support\BackupCipher;
use Throwable;

class RestoreCommand extends Command
{
    protected $signature = 'monitor:restore
        {id? : The backup id, shown in the dashboard and returned at upload time}
        {--file= : Decrypt a blob you have already downloaded, instead of fetching it}
        {--output= : Where to write the decrypted backup}
        {--passphrase= : Team passphrase (prompted if omitted)}';

    protected $description = 'Decrypt an encrypted backup locally with the team passphrase, fetching it from the vault or reading one you downloaded';

    public function handle(Transport $transport, BackupCipher $cipher): int
    {
        $id = $this->argument('id');
        $downloaded = $this->option('file');

        // Le tableau de bord propose un bouton Télécharger, et ce qu'il rend
        // est le blob scellé : c'est tout ce que le serveur détient. Sans
        // cette option il n'existait aucun moyen de l'ouvrir, la commande
        // retéléchargeant toujours la sienne, si bien que le bouton livrait un
        // fichier dont on ne pouvait rien faire.
        if ($id === null && $downloaded === null) {
            $this->error('Give a backup id, or --file=<blob> to decrypt one you already downloaded.');

            return self::FAILURE;
        }

        if ($downloaded !== null && ! is_readable($downloaded)) {
            $this->error("Cannot read {$downloaded}.");

            return self::FAILURE;
        }

        $key = $transport->getEncryptionKey();

        if (! is_array($key) || blank($key['public_key'] ?? null) || blank($key['private_key_wrapped'] ?? null)) {
            // Comme pour monitor:backup : le serveur distingue « jamais activé »,
            // « clé invalide », « forfait sans sauvegardes » et « je ne suis pas
            // ce serveur », et ces quatre-là arrivaient ici en un seul null.
            $this->error($transport->lastError() !== null
                ? 'Could not fetch the encryption key: '.$transport->lastError()
                : 'Encryption is not configured for this team.');

            return self::FAILURE;
        }

        $passphrase = $this->option('passphrase') ?: $this->secret('Team passphrase');

        try {
            $secret = $cipher->unwrapPrivateKey($key['private_key_wrapped'], $key['kdf_salt'], (string) $passphrase);
        } catch (Throwable) {
            $this->error('Wrong passphrase, cannot unwrap the private key.');

            return self::FAILURE;
        }

        // Le fichier de l'appelant n'est jamais le nôtre : on ne le supprime pas.
        $blob = $downloaded;
        $temporary = null;

        if ($blob === null) {
            $blob = $temporary = rtrim(sys_get_temp_dir(), '/').'/monitor-restore-'.bin2hex(random_bytes(8)).'.enc';

            if (! $transport->downloadBackup($id, $blob)) {
                @unlink($blob);
                $this->error($transport->lastError() !== null
                    ? 'Failed to download the backup: '.$transport->lastError()
                    : 'Failed to download the backup (check the id and your credentials).');

                return self::FAILURE;
            }
        }

        // Le serveur publie le sha256 de chaque blob et personne ne le
        // comparait. Un octet perdu en route donnait « Decryption failed »,
        // c'est à dire le message qu'on obtient aussi avec une mauvaise phrase
        // de passe : le client ne pouvait pas distinguer « votre secret est
        // faux » de « le transfert est abîmé », et les deux ne se réparent pas
        // du tout de la même façon.
        // Sans identifiant il n'y a rien à qui demander la somme attendue :
        // la vérification est alors absente, et la commande le dit plutôt que
        // de laisser croire qu'elle a eu lieu.
        if ($id !== null && ! $this->checksumMatches($transport, $id, $blob)) {
            if ($temporary !== null) {
                @unlink($temporary);
            }

            return self::FAILURE;
        }

        if ($id === null) {
            $this->warn('No id given, so the archive was not checked against the checksum the server publishes.');
        }

        $output = $this->option('output')
            ?: base_path('restored-'.($id ?? pathinfo((string) $downloaded, PATHINFO_FILENAME)).'.out');

        try {
            $cipher->decryptFile($blob, $output, $key['public_key'], $secret);
        } catch (Throwable $e) {
            $this->error('Decryption failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($temporary !== null) {
                @unlink($temporary);
            }
        }

        $this->info("Backup restored to {$output}.");

        return self::SUCCESS;
    }

    /**
     * Le blob téléchargé est-il celui que le serveur dit avoir.
     *
     * Vrai aussi quand le serveur ne publie pas de somme, ou quand la liste est
     * hors d'atteinte : la restauration doit rester possible quand la
     * vérification, elle, ne l'est pas. Ce contrôle protège d'un transfert
     * abîmé, il n'est pas une autorisation.
     */
    private function checksumMatches(Transport $transport, string $id, string $blob): bool
    {
        $backups = $transport->listBackups();

        if ($backups === null) {
            return true;
        }

        $expected = null;

        foreach ($backups as $backup) {
            if (($backup['id'] ?? null) === $id) {
                $expected = $backup['checksum'] ?? null;
                break;
            }
        }

        if (! is_string($expected) || $expected === '') {
            return true;
        }

        $actual = hash_file('sha256', $blob);

        if (hash_equals($expected, (string) $actual)) {
            return true;
        }

        $this->error(
            'The downloaded backup does not match the checksum the server holds for it. '.
            'Nothing was decrypted; try the download again.'
        );

        return false;
    }
}
