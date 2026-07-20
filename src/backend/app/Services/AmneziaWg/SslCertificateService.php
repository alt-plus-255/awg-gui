<?php

namespace App\Services\AmneziaWg;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class SslCertificateService
{
    private const CERTBOT_CONTAINER = 'awggui-certbot';

    private const CHALLENGE_WAIT_SECONDS = 90;

    private const CERTBOT_FINISH_SECONDS = 180;

    public function __construct(private AmneziaWgService $awg) {}

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
        return $this->hostGuiDir().'/certbot/challenge';
    }

    public function hooksDir(): string
    {
        return $this->hostGuiDir().'/certbot/hooks';
    }

    public function isSslEnabled(): bool
    {
        return filter_var(Setting::getValue('ssl_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        // Auto-recover: certbot succeeded but UI/state still shows error.
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

        $challenge = $this->readPendingChallenge();
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
            'hint' => 'Для выпуска и обновления сертификата Let\'s Encrypt недостаточно A-записи на IP панели. В DNS домена нужно будет дополнительно создать TXT-запись _acme-challenge (имя и значение покажем после нажатия «Выпустить» / «Обновить»). После добавления записи подтвердите выпуск в панели.',
        ];
    }

    public function hasLiveCertificate(): bool
    {
        $live = $this->hostGuiDir().'/certs/live/panel';

        return is_readable($live.'/fullchain.pem') && is_readable($live.'/privkey.pem');
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
        $dir = $this->challengeDir();
        $ready = $dir.'/ready';
        $validation = $dir.'/validation';
        $domainFile = $dir.'/domain';

        if (! is_file($ready) || ! is_readable($validation) || ! is_readable($domainFile)) {
            return null;
        }

        if (is_file($dir.'/done')) {
            return null;
        }

        $domain = trim((string) file_get_contents($domainFile));
        $value = trim((string) file_get_contents($validation));
        if ($domain === '' || $value === '') {
            return null;
        }

        return [
            'domain' => $domain,
            'txt_name' => '_acme-challenge.'.$domain,
            'txt_value' => $value,
        ];
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
        $content = $ssl ? $this->buildSslCaddyfile() : $this->buildHttpCaddyfile();
        if (file_put_contents($this->caddyfilePath(), $content) === false) {
            throw new RuntimeException('Не удалось записать Caddyfile');
        }
    }

    /**
     * Start DNS-01 issuance (or renew). Returns TXT challenge for the user.
     *
     * @return array{txt_name:string,txt_value:string,domain:string,email:string}
     */
    public function startIssue(string $email, bool $forceRenew = false): array
    {
        $domain = $this->awg->resolvePanelDomain();
        if ($domain === '') {
            throw new \InvalidArgumentException('Сначала укажите и сохраните домен панели.');
        }

        $email = trim($email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Укажите корректный email для Let\'s Encrypt.');
        }

        $endpoint = trim((string) Setting::getValue('server_endpoint', env('SERVER_ENDPOINT', 'auto')));
        $this->awg->assertDomainPointsToPublicIp($domain, $endpoint);

        $existing = $this->readPendingChallenge();
        if ($existing !== null && $this->isCertbotRunning()) {
            Setting::setValue('ssl_email', $email);
            Setting::setValue('ssl_status', 'pending');
            Setting::setValue('ssl_error', '');

            return array_merge($existing, ['email' => $email]);
        }

        $this->abortChallenge(quiet: true);
        $this->ensureHostLayout();
        $this->ensureCertbotHooks();

        Setting::setValue('ssl_email', $email);
        Setting::setValue('ssl_status', 'pending');
        Setting::setValue('ssl_error', '');

        $this->removeFile($this->challengeDir().'/ready');
        $this->removeFile($this->challengeDir().'/validation');
        $this->removeFile($this->challengeDir().'/domain');
        $this->removeFile($this->challengeDir().'/done');
        $this->removeFile($this->challengeDir().'/abort');

        $args = [
            'docker', 'run', '-d',
            '--name', self::CERTBOT_CONTAINER,
            '-v', '/etc/awg-gui/certs:/etc/letsencrypt',
            '-v', '/etc/awg-gui/certbot/hooks:/hooks:ro',
            '-v', '/etc/awg-gui/certbot/challenge:/challenge',
            'certbot/certbot',
            'certonly',
            '--manual',
            '--preferred-challenges', 'dns',
            '--manual-auth-hook', '/hooks/auth.sh',
            '--manual-cleanup-hook', '/hooks/cleanup.sh',
            '--email', $email,
            '--agree-tos',
            '--no-eff-email',
            '--non-interactive',
            '--cert-name', 'panel',
            '-d', $domain,
        ];

        if ($forceRenew || is_readable($this->hostGuiDir().'/certs/live/panel/fullchain.pem')) {
            $args[] = '--force-renewal';
        }

        $start = Process::timeout(60)->run($args);
        if (! $start->successful()) {
            Setting::setValue('ssl_status', 'error');
            $err = trim($start->errorOutput() ?: $start->output()) ?: 'Не удалось запустить certbot';
            Setting::setValue('ssl_error', $err);
            throw new RuntimeException($err);
        }

        $deadline = time() + self::CHALLENGE_WAIT_SECONDS;
        while (time() < $deadline) {
            $challenge = $this->readPendingChallenge();
            if ($challenge !== null) {
                return array_merge($challenge, ['email' => $email]);
            }

            if (! $this->isCertbotRunning()) {
                $logs = $this->certbotLogs();
                $this->cleanupCertbotContainer();
                if ($this->hasLiveCertificate() || str_contains($logs, 'Successfully received certificate')) {
                    $this->activateInstalledCertificate();

                    return [
                        'activated' => true,
                        'email' => $email,
                        'domain' => $domain,
                        'txt_name' => '',
                        'txt_value' => '',
                    ];
                }
                Setting::setValue('ssl_status', 'error');
                Setting::setValue('ssl_error', $logs !== '' ? $logs : 'Certbot завершился до появления DNS challenge');
                throw new RuntimeException(Setting::getValue('ssl_error'));
            }

            usleep(500_000);
        }

        $this->abortChallenge(quiet: true);
        Setting::setValue('ssl_status', 'error');
        Setting::setValue('ssl_error', 'Таймаут ожидания DNS challenge от certbot');
        throw new RuntimeException(Setting::getValue('ssl_error'));
    }

    /**
     * After the user added the TXT record — finish issuance and enable HTTPS.
     *
     * @return array<string, mixed>
     */
    public function completeIssue(): array
    {
        $challenge = $this->readPendingChallenge();
        if ($challenge === null && ! $this->isCertbotRunning()) {
            $recovered = $this->recoverIfCertificateExists();
            if ($recovered !== null) {
                return $recovered;
            }
            throw new \InvalidArgumentException('Нет активного DNS challenge. Сначала нажмите «Выпустить» или «Обновить».');
        }

        $done = $this->challengeDir().'/done';
        if (@file_put_contents($done, '1') === false) {
            throw new RuntimeException('Не удалось подтвердить DNS challenge');
        }

        $deadline = time() + self::CERTBOT_FINISH_SECONDS;
        while (time() < $deadline) {
            if (! $this->isCertbotRunning()) {
                break;
            }
            usleep(500_000);
        }

        if ($this->isCertbotRunning()) {
            throw new RuntimeException('Certbot всё ещё выполняется. Проверьте TXT-запись и подождите, затем повторите.');
        }

        $exit = $this->certbotExitCode();
        $logs = $this->certbotLogs();
        $this->cleanupCertbotContainer();

        $liveOk = $this->hasLiveCertificate();
        $logsSayOk = str_contains($logs, 'Successfully received certificate')
            || str_contains($logs, 'Certificate not yet due for renewal');

        if ($exit !== 0 && ! $liveOk) {
            Setting::setValue('ssl_status', 'error');
            Setting::setValue('ssl_error', $logs !== '' ? $logs : "Certbot завершился с кодом {$exit}");
            throw new RuntimeException(Setting::getValue('ssl_error'));
        }

        if (! $liveOk && ! $logsSayOk) {
            Setting::setValue('ssl_status', 'error');
            Setting::setValue('ssl_error', $logs !== '' ? $logs : 'Файлы сертификата не найдены после certbot');
            throw new RuntimeException(Setting::getValue('ssl_error'));
        }

        if (! $liveOk) {
            $recovered = $this->recoverIfCertificateExists();
            if ($recovered !== null) {
                return $recovered;
            }
            Setting::setValue('ssl_status', 'error');
            Setting::setValue('ssl_error', $logs !== '' ? $logs : 'Файлы сертификата не найдены после certbot');
            throw new RuntimeException(Setting::getValue('ssl_error'));
        }

        return $this->activateInstalledCertificate();
    }

    /**
     * Enable HTTPS using certs already present under /etc/awg-gui/certs/live/panel.
     *
     * @return array<string, mixed>
     */
    public function activateInstalledCertificate(): array
    {
        $this->installPanelCertsFromLetsEncrypt();
        $expiresAt = $this->readCertExpiresAt($this->certsPanelDir().'/fullchain.pem') ?? '';

        Setting::setValue('ssl_enabled', '1');
        Setting::setValue('ssl_status', 'active');
        Setting::setValue('ssl_error', '');
        Setting::setValue('ssl_expires_at', $expiresAt);

        $this->writeCaddyfile(true);
        $this->awg->writeWebhookConf();
        $this->awg->syncPanelUrlToHostEnv();
        // Ports already published — reload config only (no image rebuild).
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
        $dir = $this->challengeDir();
        if (is_dir($dir)) {
            @file_put_contents($dir.'/abort', '1');
        }

        if ($this->isCertbotRunning()) {
            Process::timeout(30)->run(['docker', 'rm', '-f', self::CERTBOT_CONTAINER]);
        } else {
            $this->cleanupCertbotContainer();
        }

        foreach (['ready', 'done', 'abort', 'domain', 'validation', 'failed'] as $name) {
            $this->removeFile($dir.'/'.$name);
        }

        if (! $quiet && Setting::getValue('ssl_status') === 'pending' && ! $this->isSslEnabled()) {
            Setting::setValue('ssl_status', 'disabled');
        }
    }

    public function recreateCaddy(): void
    {
        $composeDir = rtrim((string) env('HOST_COMPOSE_DIR', '/compose'), '/');
        $composeFile = $composeDir.'/docker-compose.yml';
        $envFile = $composeDir.'/.env';

        if (! is_file($composeFile) || ! is_file($envFile)) {
            Log::warning('compose files not reachable for caddy recreate', [
                'compose' => $composeFile,
                'env' => $envFile,
            ]);
            $this->reloadCaddy();

            return;
        }

        $result = Process::timeout(180)->run([
            'docker', 'compose',
            '-p', 'awggui',
            '--env-file', $envFile,
            '-f', $composeFile,
            'up', '-d', '--force-recreate', '--no-deps', 'caddy',
        ]);

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());
            Log::error('caddy recreate failed', ['err' => $err]);
            throw new RuntimeException($err !== '' ? $err : 'Не удалось пересоздать контейнер Caddy');
        }
    }

    public function reloadCaddy(): void
    {
        $result = Process::timeout(30)->run([
            'docker', 'exec', 'awggui-caddy',
            'caddy', 'reload', '--config', '/etc/caddy/Caddyfile',
        ]);

        if (! $result->successful()) {
            $err = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException($err !== '' ? $err : 'Не удалось перезагрузить Caddy');
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

    private function installPanelCertsFromLetsEncrypt(): void
    {
        $live = $this->hostGuiDir().'/certs/live/panel';
        $fullchain = $live.'/fullchain.pem';
        $privkey = $live.'/privkey.pem';

        if (! is_readable($fullchain) || ! is_readable($privkey)) {
            throw new RuntimeException('Certbot завершился успешно, но файлы сертификата не найдены');
        }

        $dest = $this->certsPanelDir();
        if (! is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        // Follow Let's Encrypt symlinks to real files for the Caddy bind mount.
        $fc = file_get_contents($fullchain);
        $pk = file_get_contents($privkey);
        if ($fc === false || $pk === false || $fc === '' || $pk === '') {
            throw new RuntimeException('Не удалось прочитать выпущенный сертификат');
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

    private function isCertbotRunning(): bool
    {
        $result = Process::run(['docker', 'inspect', '-f', '{{.State.Running}}', self::CERTBOT_CONTAINER]);

        return $result->successful() && trim($result->output()) === 'true';
    }

    private function certbotExitCode(): int
    {
        $result = Process::run(['docker', 'inspect', '-f', '{{.State.ExitCode}}', self::CERTBOT_CONTAINER]);
        if (! $result->successful()) {
            return 1;
        }

        return (int) trim($result->output());
    }

    private function certbotLogs(): string
    {
        $result = Process::timeout(15)->run(['docker', 'logs', '--tail', '80', self::CERTBOT_CONTAINER]);
        $out = trim($result->output()."\n".$result->errorOutput());

        return mb_substr($out, 0, 2000);
    }

    private function cleanupCertbotContainer(): void
    {
        Process::timeout(30)->run(['docker', 'rm', '-f', self::CERTBOT_CONTAINER]);
    }

    private function ensureHostLayout(): void
    {
        foreach ([
            $this->hostGuiDir(),
            $this->certsPanelDir(),
            $this->challengeDir(),
            $this->hooksDir(),
            $this->hostGuiDir().'/certs',
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    private function ensureCertbotHooks(): void
    {
        $auth = $this->hooksDir().'/auth.sh';
        $cleanup = $this->hooksDir().'/cleanup.sh';

        if (! is_file($auth)) {
            file_put_contents($auth, <<<'SH'
#!/bin/sh
set -eu
printf '%s' "${CERTBOT_DOMAIN}" > /challenge/domain
printf '%s' "${CERTBOT_VALIDATION}" > /challenge/validation
rm -f /challenge/done /challenge/abort /challenge/failed
touch /challenge/ready
i=0
while [ ! -f /challenge/done ]; do
	if [ -f /challenge/abort ]; then
		echo "DNS challenge aborted by user" >&2
		exit 1
	fi
	sleep 2
	i=$((i + 2))
	if [ "$i" -ge 1800 ]; then
		echo "Timeout waiting for DNS TXT confirmation" >&2
		exit 1
	fi
done
SH);
        }

        if (! is_file($cleanup)) {
            file_put_contents($cleanup, <<<'SH'
#!/bin/sh
set -eu
rm -f /challenge/ready /challenge/done /challenge/abort
rm -f /challenge/failed
SH);
        }

        @chmod($auth, 0755);
        @chmod($cleanup, 0755);
    }

    private function buildHttpCaddyfile(): string
    {
        return $this->siteBlock(':80', false);
    }

    private function buildSslCaddyfile(): string
    {
        $domain = $this->awg->resolvePanelDomain();
        $httpsPort = (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));
        $redirect = '';
        if ($domain !== '') {
            $redirect = <<<CADDY
	@panel host {$domain}
	redir @panel https://{$domain}:{$httpsPort}{uri} permanent

CADDY;
        }

        return "{\n\tauto_https off\n}\n\n"
            .$this->siteBlock(':443', true)."\n"
            .$this->siteBlock(':80', false, $redirect);
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

	handle {
		root * /srv
		try_files {path} /index.html
		file_server
	}
}

CADDY;
    }

    private function removeFile(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
