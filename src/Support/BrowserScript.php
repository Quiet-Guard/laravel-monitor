<?php

namespace QuietGuard\LaravelMonitor\Support;

use Illuminate\Contracts\Foundation\Application;
use QuietGuard\Monitor\Config;
use Throwable;

/**
 * What `@quietGuardBrowser` prints: the browser error script, inline, after a
 * small JSON configuration (the endpoint, the release, the environment).
 *
 * Nothing at all without a browser key, or when the same gates as exception
 * reporting refuse (the master switch, the environments list). Inline rather
 * than an asset to publish, so an upgrade of the package updates the script
 * and there is no file to keep in step. A nonce is set when the application
 * gives Vite one, so a strict Content-Security-Policy accepts the script.
 */
final class BrowserScript
{
    /**
     * @param  array<string, mixed>  $config  the `monitor` configuration
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $config,
        private readonly ?string $release,
    ) {}

    public function render(): string
    {
        try {
            return $this->build();
        } catch (Throwable) {
            // A layout must never fail because of monitoring.
            return '';
        }
    }

    private function build(): string
    {
        $key = trim((string) ($this->config['browser_key'] ?? ''));

        if ($key === '' || ! ($this->config['enabled'] ?? false)) {
            return '';
        }

        $environments = $this->config['environments'] ?? [];

        if ($environments !== [] && ! in_array($this->app->environment(), $environments, true)) {
            return '';
        }

        $settings = json_encode([
            'endpoint' => Config::normaliseUrl($this->config['url'] ?? null).'/api/v1/browser/'.rawurlencode($key),
            'release' => $this->release,
            'environment' => $this->app->environment(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $script = (string) file_get_contents(__DIR__.'/../../resources/js/quiet-guard-browser.js');
        $nonce = $this->nonce();

        return '<script'.($nonce === null ? '' : ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES).'"').'>'
            .'window.QuietGuardBrowser='.$settings.";\n".$script
            .'</script>';
    }

    private function nonce(): ?string
    {
        $vite = 'Illuminate\Foundation\Vite';

        if (! class_exists($vite)) {
            return null;
        }

        $nonce = $this->app->make($vite)->cspNonce();

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }
}
