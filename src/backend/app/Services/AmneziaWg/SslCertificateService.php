<?php

namespace App\Services\AmneziaWg;

use App\Models\Setting;
use App\Services\Docker\DockerRuntime;
use App\Services\Docker\PanelOpsClient;
use App\Services\Ssl\AcmeDnsIssuer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class SslCertificateService
{
    private ?AcmeDnsIssuer $issuer = null;

    public function __construct(
        private AmneziaWgService $awg,
        private DockerRuntime $docker,
        private PanelOpsClient $panelOps,
    ) {}

    public function hostGuiDir(): string
    {
        return $this->awg->hostGuiDir();
    }

    public function caddyfilePath(): string
    {
        return $this->hostGuiDir().'/Caddyfile';
    }

    public function certsPanelDir(): string
    {
        return $this->hostGuiDir().'/certs/panel';
    }

    public function challengeDir(): string
    {
        return $this->issuer()->challengeDir();
    }

    public function isSslEnabled(): bool
    {
        return filter_var(Setting::getValue('ssl_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        // Auto-recover: issue succeeded but UI/state still shows error.
        if (! $this->isSslEnabled() && $this->hasLiveCertificate()) {
            $status = trim((string) Setting::getValue('ssl_status', 'disabled'));
            $error = trim((string) Setting::getValue('ssl_error', ''));
            if ($status === 'error' || str_contains($error, 'Successfully received certificate')) {
                try {
                    $this->activateInstalledCertificate();
                } catch (\Throwable $e) {
                    Log::warning('ssl auto-recover failed', ['err' => $e->getMessage()]);
                }
            }
        }

        $enabled = $this->isSslEnabled();
        $email = trim((string) Setting::getValue('ssl_email', ''));
        $status = trim((string) Setting::getValue('ssl_status', $enabled ? 'active' : 'disabled'));
        $error = trim((string) Setting::getValue('ssl_error', ''));
        $expiresAt = trim((string) Setting::getValue('ssl_expires_at', ''));
        $domain = $this->awg->resolvePanelDomain();
        $httpsPort = (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));

        $challenge = $this->resolvePendingChallenge();
        if ($challenge !== null && in_array($status, ['disabled', 'active', 'error'], true)) {
            $status = 'pending';
        }

        if ($expiresAt === '' && is_readable($this->certsPanelDir().'/fullchain.pem')) {
            $expiresAt = $this->readCertExpiresAt($this->certsPanelDir().'/fullchain.pem') ?? '';
            if ($expiresAt !== '') {
                Setting::setValue('ssl_expires_at', $expiresAt);
            }
        }

        return [
            'enabled' => $enabled,
            'email' => $email,
            'status' => $status,
            'error' => $error,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
            'domain' => $domain,
            'https_port' => $httpsPort,
            'challenge' => $challenge,
            'panel_url' => $this->awg->resolvePanelUrl(),
            'hint' => __('settings.ssl_dns_hint'),
        ];
    }

    public function hasLiveCertificate(): bool
    {
        $live = $this->hostGuiDir().'/certs/live/panel';
        if (is_readable($live.'/fullchain.pem') && is_readable($live.'/privkey.pem')) {
            return true;
        }

        return is_readable($this->certsPanelDir().'/fullchain.pem')
            && is_readable($this->certsPanelDir().'/privkey.pem');
    }

    /**
     * If LE cert files exist, enable HTTPS even after a false-negative error.
     *
     * @return array<string, mixed>|null
     */
    public function recoverIfCertificateExists(): ?array
    {
        if (! $this->hasLiveCertificate() && ! is_readable($this->certsPanelDir().'/fullchain.pem')) {
            return null;
        }

        return $this->activateInstalledCertificate();
    }

    /**
     * @return array{txt_name:string,txt_value:string,domain:string}|null
     */
    public function readPendingChallenge(): ?array
    {
        return $this->resolvePendingChallenge();
    }

    public function ensureHttpCaddyfile(): void
    {
        $this->ensureHostLayout();
        $path = $this->caddyfilePath();
        if (! is_file($path)) {
            file_put_contents($path, $this->buildHttpCaddyfile());
        }
    }

    public function writeCaddyfile(bool $ssl): void
    {
        $this->ensureHostLayout();
        $content = $this->caddyfileContents($ssl);
        if (file_put_contents($this->caddyfilePath(), $content) === false) {
            throw new RuntimeException(__('settings.caddyfile_write_failed'));
        }
    }

    /** Public for unit tests and callers that only need the rendered config. */
    public function caddyfileContents(bool $ssl): string
    {
        return $ssl ? $this->buildSslCaddyfile() : $this->buildHttpCaddyfile();
    }

    /**
     * Re-render SSL Caddyfile when redirect / domain options change (no-op if SSL off).
     */
    public function refreshSslCaddyfileIfEnabled(): void
    {
        if (! $this->isSslEnabled()) {
            return;
        }

        $this->writeCaddyfile(true);
        $this->reloadOrRecreateCaddy();
    }

    /**
     * Start DNS-01 issuance (or renew). Returns TXT challenge for the user.
     * Reuses the same TXT for the current domain until abort/complete so DNS
     * propagation (up to ~1h) is not reset by page reloads or repeated Issue clicks.
     *
     * @return array{txt_name:string,txt_value:string,domain:string,email:string}
     */
    public function startIssue(string $email, bool $forceRenew = false): array
    {
        $domain = $this->awg->resolvePanelDomain();
        if ($domain === '') {
            throw new \InvalidArgumentException(__('settings.panel_domain_required'));
        }

        $email = trim($email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(__('settings.le_email_required'));
        }

        $this->awg->assertPanelDomainDns($domain);

        // Keep the same TXT for this domain — do not open a new ACME order while pending exists.
        $existing = $this->issuer()->reusableChallengeFor($domain);
        if ($existing !== null) {
            Setting::setValue('ssl_email', $email);
            Setting::setValue('ssl_status', 'pending');
            Setting::setValue('ssl_error', '');
            $this->storeChallenge($existing);

            return array_merge($existing, ['email' => $email]);
        }

        // forceRenew only matters when there is no reusable pending challenge.
        $this->abortChallenge(quiet: true);
        $this->ensureHostLayout();

        Setting::setValue('ssl_email', $email);
        Setting::setValue('ssl_status', 'pending');
        Setting::setValue('ssl_error', '');

        try {
            $challenge = $this->issuer()->start($domain, $email, forceNew: $forceRenew);
            $this->storeChallenge($challenge);
        } catch (\Throwable $e) {
            $this->clearStoredChallenge();
            Setting::setValue('ssl_status', 'error');
            $err = trim($e->getMessage()) !== '' ? $e->getMessage() : __('settings.acme_start_failed');
            Setting::setValue('ssl_error', $err);
            throw new RuntimeException($err, 0, $e);
        }

        return array_merge($challenge, ['email' => $email]);
    }

    /**
     * After the user added the TXT record — finish issuance and enable HTTPS.
     *
     * @return array<string, mixed>
     */
    public function completeIssue(): array
    {
        $challenge = $this->readPendingChallenge();
        if ($challenge === null && ! $this->issuer()->hasPendingOrder()) {
            $recovered = $this->recoverIfCertificateExists();
            if ($recovered !== null) {
                return $recovered;
            }
            throw new \InvalidArgumentException(__('settings.no_active_dns_challenge'));
        }

        try {
            $this->issuer()->complete();
        } catch (\Throwable $e) {
            if ($this->hasLiveCertificate()) {
                return $this->activateInstalledCertificate();
            }
            Setting::setValue('ssl_status', 'error');
            $err = trim($e->getMessage()) !== '' ? $e->getMessage() : __('settings.acme_complete_failed');
            Setting::setValue('ssl_error', $err);
            throw new RuntimeException($err, 0, $e);
        }

        if (! $this->hasLiveCertificate()) {
            $recovered = $this->recoverIfCertificateExists();
            if ($recovered !== null) {
                return $recovered;
            }
            Setting::setValue('ssl_status', 'error');
            Setting::setValue('ssl_error', __('settings.cert_files_not_found_after_issue'));
            throw new RuntimeException(Setting::getValue('ssl_error'));
        }

        return $this->activateInstalledCertificate();
    }

    /**
     * Enable HTTPS using certs already present under certs/live/panel or certs/panel.
     *
     * @return array<string, mixed>
     */
    public function activateInstalledCertificate(): array
    {
        $this->clearStoredChallenge();
        $this->installPanelCertsFromLetsEncrypt();
        $expiresAt = $this->readCertExpiresAt($this->certsPanelDir().'/fullchain.pem') ?? '';

        Setting::setValue('ssl_enabled', '1');
        Setting::setValue('ssl_status', 'active');
        Setting::setValue('ssl_error', '');
        Setting::setValue('ssl_expires_at', $expiresAt);

        $this->writeCaddyfile(true);
        $this->awg->writeWebhookConf();
        $this->awg->syncPanelUrlToHostEnv();
        $this->reloadOrRecreateCaddy();

        return $this->status();
    }

    public function disable(): array
    {
        $this->abortChallenge(quiet: true);

        Setting::setValue('ssl_enabled', '0');
        Setting::setValue('ssl_status', 'disabled');
        Setting::setValue('ssl_error', '');

        $this->writeCaddyfile(false);
        $this->awg->writeWebhookConf();
        $this->awg->syncPanelUrlToHostEnv();
        $this->reloadOrRecreateCaddy();

        return $this->status();
    }

    public function abortChallenge(bool $quiet = false): void
    {
        $this->issuer()->abort();
        $this->clearStoredChallenge();

        if (! $quiet && Setting::getValue('ssl_status') === 'pending' && ! $this->isSslEnabled()) {
            Setting::setValue('ssl_status', 'disabled');
        }
    }

    public function recreateCaddy(): void
    {
        try {
            $this->panelOps->recreateCaddy();
        } catch (\Throwable $e) {
            Log::error('caddy recreate failed', ['err' => $e->getMessage()]);
            throw new RuntimeException($e->getMessage() !== '' ? $e->getMessage() : __('settings.caddy_recreate_failed'));
        }
    }

    public function reloadCaddy(): void
    {
        $result = $this->docker->exec(
            'awggui-caddy',
            ['caddy', 'reload', '--config', '/etc/caddy/Caddyfile'],
            timeout: 30,
        );

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException($err !== '' ? $err : __('settings.caddy_reload_failed'));
        }
    }

    public function reloadOrRecreateCaddy(): void
    {
        try {
            $this->reloadCaddy();
        } catch (\Throwable $e) {
            Log::warning('caddy reload failed, recreating', ['err' => $e->getMessage()]);
            $this->recreateCaddy();
        }
    }

    private function issuer(): AcmeDnsIssuer
    {
        if ($this->issuer === null) {
            $directory = env('ACME_DIRECTORY_URL') ?: null;
            $this->issuer = new AcmeDnsIssuer($this->hostGuiDir(), is_string($directory) ? $directory : null);
        }

        return $this->issuer;
    }

    private function installPanelCertsFromLetsEncrypt(): void
    {
        $live = $this->hostGuiDir().'/certs/live/panel';
        $fullchain = $live.'/fullchain.pem';
        $privkey = $live.'/privkey.pem';

        if (! is_readable($fullchain) || ! is_readable($privkey)) {
            $fullchain = $this->certsPanelDir().'/fullchain.pem';
            $privkey = $this->certsPanelDir().'/privkey.pem';
        }

        if (! is_readable($fullchain) || ! is_readable($privkey)) {
            throw new RuntimeException(__('settings.acme_ok_but_files_missing'));
        }

        $dest = $this->certsPanelDir();
        if (! is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $fc = file_get_contents($fullchain);
        $pk = file_get_contents($privkey);
        if ($fc === false || $pk === false || $fc === '' || $pk === '') {
            throw new RuntimeException(__('settings.cert_read_failed'));
        }

        file_put_contents($dest.'/fullchain.pem', $fc);
        file_put_contents($dest.'/privkey.pem', $pk);
        @chmod($dest.'/privkey.pem', 0640);
    }

    private function readCertExpiresAt(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $cert = openssl_x509_parse($raw);
        if (! is_array($cert) || empty($cert['validTo_time_t'])) {
            return null;
        }

        return gmdate('c', (int) $cert['validTo_time_t']);
    }

    private function ensureHostLayout(): void
    {
        $this->issuer()->ensureLayout();
        foreach ([
            $this->hostGuiDir(),
            $this->certsPanelDir(),
            $this->hostGuiDir().'/certs',
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * Challenge from ACME files, mirrored into Settings for page-reload survival.
     *
     * @return array{txt_name:string,txt_value:string,domain:string}|null
     */
    private function resolvePendingChallenge(): ?array
    {
        $domain = strtolower(trim($this->awg->resolvePanelDomain()));
        $fromFiles = $this->issuer()->readPendingChallenge();
        if ($fromFiles !== null) {
            if ($domain !== '' && ($fromFiles['domain'] ?? '') !== $domain) {
                // Stale challenge for a previous panel domain.
                return null;
            }
            $this->storeChallenge($fromFiles);

            return $fromFiles;
        }

        $stored = $this->readStoredChallenge();
        if ($stored === null) {
            return null;
        }
        if ($domain !== '' && ($stored['domain'] ?? '') !== $domain) {
            $this->clearStoredChallenge();

            return null;
        }

        // Prefer not to show a DB-only challenge that can no longer be completed.
        if (! $this->issuer()->hasPendingOrder()) {
            $this->clearStoredChallenge();

            return null;
        }

        return $stored;
    }

    /**
     * @param  array{txt_name?:string,txt_value?:string,domain?:string}  $challenge
     */
    private function storeChallenge(array $challenge): void
    {
        $domain = strtolower(trim((string) ($challenge['domain'] ?? '')));
        $txtName = trim((string) ($challenge['txt_name'] ?? ''));
        $txtValue = trim((string) ($challenge['txt_value'] ?? ''));
        if ($domain === '' || $txtValue === '') {
            return;
        }
        if ($txtName === '') {
            $txtName = '_acme-challenge.'.$domain;
        }

        Setting::setValue('ssl_pending_challenge', json_encode([
            'domain' => $domain,
            'txt_name' => $txtName,
            'txt_value' => $txtValue,
            'saved_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array{txt_name:string,txt_value:string,domain:string}|null
     */
    private function readStoredChallenge(): ?array
    {
        $raw = trim((string) Setting::getValue('ssl_pending_challenge', ''));
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }
        $domain = strtolower(trim((string) ($data['domain'] ?? '')));
        $txtValue = trim((string) ($data['txt_value'] ?? ''));
        $txtName = trim((string) ($data['txt_name'] ?? ''));
        if ($domain === '' || $txtValue === '') {
            return null;
        }
        if ($txtName === '') {
            $txtName = '_acme-challenge.'.$domain;
        }

        return [
            'domain' => $domain,
            'txt_name' => $txtName,
            'txt_value' => $txtValue,
        ];
    }

    private function clearStoredChallenge(): void
    {
        Setting::setValue('ssl_pending_challenge', '');
    }

    private function buildHttpCaddyfile(): string
    {
        return $this->siteBlock(':80', false);
    }

    private function buildSslCaddyfile(): string
    {
        $domain = $this->awg->resolvePanelDomain();
        $httpsPort = (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));
        $httpRedirect = '';
        $httpsRedirect = '';
        if ($domain !== '') {
            // Always upgrade domain HTTP → HTTPS.
            $httpRedirect = <<<CADDY
	@panel host {$domain}
	redir @panel https://{$domain}:{$httpsPort}{uri} temporary

CADDY;
            // Optional: also send IP / other Host values to the canonical HTTPS domain.
            if ($this->awg->shouldRedirectIpToDomain()) {
                $forceDomain = <<<CADDY
	@not_panel not host {$domain}
	redir @not_panel https://{$domain}:{$httpsPort}{uri} temporary

CADDY;
                $httpRedirect .= $forceDomain;
                $httpsRedirect = $forceDomain;
            }
        }

        return "{\n\tauto_https off\n}\n\n"
            .$this->siteBlock(':443', true, $httpsRedirect)."\n"
            .$this->siteBlock(':80', false, $httpRedirect);
    }

    private function siteBlock(string $listen, bool $tls, string $extra = ''): string
    {
        $tlsLine = $tls ? "\ttls /certs/fullchain.pem /certs/privkey.pem\n" : '';

        return <<<CADDY
{$listen} {
{$tlsLine}{$extra}	encode gzip

	handle /ws* {
		reverse_proxy awggui-app:8081
	}

	handle /api/* {
		reverse_proxy awggui-app:8000 {
			header_up Host {host}
			header_up X-Real-IP {remote}
			header_up X-Forwarded-For {remote}
			header_up X-Forwarded-Proto {scheme}
		}
	}

	handle /sanctum/* {
		reverse_proxy awggui-app:8000 {
			header_up Host {host}
			header_up X-Real-IP {remote}
			header_up X-Forwarded-For {remote}
			header_up X-Forwarded-Proto {scheme}
		}
	}

	@sw path /sw.js /sw.js.map /workbox-*.js /workbox-*.js.map
	handle @sw {
		root * /srv
		header Cache-Control "no-cache"
		file_server
	}

	handle {
		root * /srv
		try_files {path} /index.html
		file_server
	}
}

CADDY;
    }
}
