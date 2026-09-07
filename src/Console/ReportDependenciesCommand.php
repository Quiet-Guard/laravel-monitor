<?php

namespace QuietGuard\LaravelMonitor\Console;

use Illuminate\Console\Command;
use QuietGuard\LaravelMonitor\Http\Transport;

class ReportDependenciesCommand extends Command
{
    protected $signature = 'monitor:dependencies {--path= : Path to composer.lock (defaults to the application base path)}';

    protected $description = 'Send this application\'s installed dependencies to Quiet Guard for vulnerability scanning';

    public function handle(Transport $transport): int
    {
        $config = config('monitor');

        if (! ($config['enabled'] ?? false)) {
            $this->warn('Quiet Guard is disabled (MONITOR_ENABLED=false). Nothing sent.');

            return self::SUCCESS;
        }

        $path = $this->option('path') ?: base_path('composer.lock');

        if (! is_file($path)) {
            $this->error("composer.lock not found at {$path}");

            return self::FAILURE;
        }

        $packages = $this->parse($path);

        if ($packages === []) {
            $this->warn('No packages found in composer.lock.');

            return self::SUCCESS;
        }

        if ($transport->sendDependencies($packages)) {
            $this->info(sprintf('Reported %d dependencies to Quiet Guard.', count($packages)));

            return self::SUCCESS;
        }

        // L'adresse appelée et le code reçu, parce que « vérifiez MONITOR_URL /
        // MONITOR_KEY » ne dit pas laquelle des deux, ni ce que le serveur a
        // répondu quand il a pris la peine de l'écrire.
        $this->error($transport->lastError() !== null
            ? 'Failed to report dependencies: '.$transport->lastError()
            : 'Failed to report dependencies (check MONITOR_URL / MONITOR_KEY).');

        return self::FAILURE;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse(string $path): array
    {
        $lock = json_decode((string) file_get_contents($path), true);

        if (! is_array($lock)) {
            return [];
        }

        $packages = [];

        foreach (['packages' => false, 'packages-dev' => true] as $key => $isDev) {
            foreach ($lock[$key] ?? [] as $package) {
                if (empty($package['name']) || empty($package['version'])) {
                    continue;
                }

                $packages[] = [
                    'name' => $package['name'],
                    'version' => ltrim((string) $package['version'], 'v'),
                    'is_dev' => $isDev,
                ];
            }
        }

        return $packages;
    }
}
