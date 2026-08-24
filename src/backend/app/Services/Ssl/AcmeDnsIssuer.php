<?php

namespace App\Services\Ssl;

use RuntimeException;

/**
 * DNS-01 Let's Encrypt issuer with pause/confirm UX (no Certbot).
 *
 * Storage under {baseDir}/acme/:
 * - account/account.pem
 * - pending/order.json + domain.pem
 * - challenge/{domain,validation,ready}
 */
class AcmeDnsIssuer
{
    public function __construct(
        private string $baseDir,
        private ?string $directoryUrl = null,
    ) {}

    public function baseDir(): string
    {
        return rtrim($this->baseDir, '/');
    }

    public function acmeDir(): string
    {
        return $this->baseDir().'/acme';
    }

    public function challengeDir(): string
    {
        return $this->acmeDir().'/challenge';
    }

    public function ensureLayout(): void
    {
        foreach ([
            $this->acmeDir(),
            $this->acmeDir().'/account',
            $this->acmeDir().'/pending',
            $this->challengeDir(),
            $this->baseDir().'/certs',
            $this->baseDir().'/certs/panel',
            $this->baseDir().'/certs/live/panel',
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }
    }

    /**
     * @return array{txt_name:string,txt_value:string,domain:string}|null
     */
    public function readPendingChallenge(): ?array
    {
        $fromFiles = $this->readChallengeFiles();
        if ($fromFiles !== null) {
            $this->backfillTxtValueInOrder($fromFiles['domain'], $fromFiles['txt_value']);

            return $fromFiles;
        }

        // Fallback: order.json keeps txt_value so UI survives a missing ready/validation file.
        if (! is_readable($this->acmeDir().'/pending/order.json')) {
            return null;
        }

        try {
            $pending = $this->loadPending();
        } catch (RuntimeException) {
            return null;
        }

        $domain = strtolower(trim((string) ($pending['domain'] ?? '')));
        $value = trim((string) ($pending['txt_value'] ?? ''));
        if ($domain === '' || $value === '') {
            return null;
        }

        // Re-materialize challenge files for older pending orders / partial writes.
        $this->writeChallengeFiles($domain, $value);

        return [
            'domain' => $domain,
            'txt_name' => '_acme-challenge.'.$domain,
            'txt_value' => $value,
        ];
    }

    public function hasPendingOrder(): bool
    {
        if (! is_readable($this->acmeDir().'/pending/order.json')) {
            return false;
        }
        if (! is_readable($this->acmeDir().'/pending/domain.pem')) {
            return false;
        }

        return $this->readPendingChallenge() !== null;
    }

    /**
     * Pending challenge for a specific domain, if an order can still be completed.
     *
     * @return array{txt_name:string,txt_value:string,domain:string}|null
     */
    public function reusableChallengeFor(string $domain): ?array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || ! $this->hasPendingOrder()) {
            return null;
        }

        $existing = $this->readPendingChallenge();
        if ($existing === null || ($existing['domain'] ?? '') !== $domain) {
            return null;
        }

        return $existing;
    }

    /**
     * Start (or reuse) a DNS-01 order. Returns TXT challenge for the UI.
     * Same domain always reuses the existing TXT until abort/complete unless $forceNew.
     *
     * @return array{txt_name:string,txt_value:string,domain:string}
     */
    public function start(string $domain, string $email, bool $forceNew = false): array
    {
        $domain = strtolower(trim($domain));
        $email = trim($email);
        if ($domain === '' || $email === '') {
            throw new RuntimeException('Domain and email are required');
        }

        $this->ensureLayout();

        if (! $forceNew) {
            $existing = $this->reusableChallengeFor($domain);
            if ($existing !== null) {
                return $existing;
            }
        }

        $this->clearPendingFiles();

        $client = $this->client();
        $this->ensureAccount($client, $email);

        $orderUrl = $client->resourceUrl('newOrder');
        $orderResp = $client->signedRequest($orderUrl, [
            'identifiers' => [
                ['type' => 'dns', 'value' => $domain],
            ],
        ], useKid: true);

        $orderBody = $orderResp['body'];
        if (! is_array($orderBody) || empty($orderBody['authorizations'][0])) {
            throw new RuntimeException(__('settings.acme_order_failed'));
        }

        $orderLocation = $orderResp['location'] ?? null;
        if (! is_string($orderLocation) || $orderLocation === '') {
            throw new RuntimeException(__('settings.acme_order_failed'));
        }

        $authzUrl = $orderBody['authorizations'][0];
        $authzResp = $client->signedRequest($authzUrl, null, useKid: true);
        $authz = $authzResp['body'];
        if (! is_array($authz) || empty($authz['challenges']) || ! is_array($authz['challenges'])) {
            throw new RuntimeException(__('settings.acme_order_failed'));
        }

        $dnsChallenge = null;
        foreach ($authz['challenges'] as $challenge) {
            if (($challenge['type'] ?? '') === 'dns-01') {
                $dnsChallenge = $challenge;
                break;
            }
        }
        if ($dnsChallenge === null || empty($dnsChallenge['token']) || empty($dnsChallenge['url'])) {
            throw new RuntimeException(__('settings.acme_dns_challenge_missing'));
        }

        $token = (string) $dnsChallenge['token'];
        $txtValue = $client->dnsTxtValue($token);
        $txtName = '_acme-challenge.'.$domain;

        $domainKeyPem = $this->generateRsaPrivateKeyPem();
        file_put_contents($this->acmeDir().'/pending/domain.pem', $domainKeyPem);
        @chmod($this->acmeDir().'/pending/domain.pem', 0600);

        $pending = [
            'domain' => $domain,
            'email' => $email,
            'order_url' => $orderLocation,
            'finalize_url' => (string) ($orderBody['finalize'] ?? ''),
            'authz_url' => $authzUrl,
            'challenge_url' => (string) $dnsChallenge['url'],
            'challenge_token' => $token,
            'txt_name' => $txtName,
            'txt_value' => $txtValue,
            'account_url' => $client->accountUrl(),
            'created_at' => gmdate('c'),
        ];
        file_put_contents(
            $this->acmeDir().'/pending/order.json',
            json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $this->writeChallengeFiles($domain, $txtValue);

        return [
            'domain' => $domain,
            'txt_name' => $txtName,
            'txt_value' => $txtValue,
        ];
    }

    /**
     * After the user added the TXT record — validate, finalize, write PEMs.
     *
     * @return array{fullchain:string,privkey:string}
     */
    public function complete(int $timeoutSeconds = 180): array
    {
        $challenge = $this->readPendingChallenge();
        if ($challenge === null || ! is_readable($this->acmeDir().'/pending/order.json')) {
            throw new RuntimeException(__('settings.no_active_dns_challenge'));
        }

        $pending = $this->loadPending();
        $domainKeyPem = (string) file_get_contents($this->acmeDir().'/pending/domain.pem');
        if ($domainKeyPem === '') {
            throw new RuntimeException(__('settings.acme_domain_key_missing'));
        }

        $client = $this->client();
        if (! empty($pending['account_url'])) {
            $client->setAccountUrl((string) $pending['account_url']);
        }
        $this->ensureAccount($client, (string) ($pending['email'] ?? ''));

        // Ask CA to validate DNS-01.
        $client->signedRequest((string) $pending['challenge_url'], new \stdClass, useKid: true);

        $deadline = time() + $timeoutSeconds;
        $status = 'pending';
        while (time() <= $deadline) {
            $authz = $client->signedRequest((string) $pending['authz_url'], null, useKid: true);
            $body = $authz['body'];
            $status = is_array($body) ? (string) ($body['status'] ?? '') : '';
            if ($status === 'valid') {
                break;
            }
            if ($status === 'invalid') {
                $detail = is_array($body) ? json_encode($body['challenges'] ?? $body) : '';
                throw new RuntimeException(__('settings.acme_challenge_invalid').($detail !== '' ? ': '.$detail : ''));
            }
            usleep(1_000_000);
        }
        if ($status !== 'valid') {
            throw new RuntimeException(__('settings.acme_challenge_timeout'));
        }

        $csrDer = $this->createCsrDer((string) $pending['domain'], $domainKeyPem);
        $csrB64 = $client->b64($csrDer);

        $finalizeUrl = (string) ($pending['finalize_url'] ?? '');
        if ($finalizeUrl === '') {
            throw new RuntimeException(__('settings.acme_order_failed'));
        }
        $client->signedRequest($finalizeUrl, ['csr' => $csrB64], useKid: true);

        $orderUrl = (string) $pending['order_url'];
        $certUrl = null;
        $orderStatus = 'processing';
        while (time() <= $deadline) {
            $order = $client->signedRequest($orderUrl, null, useKid: true);
            $body = $order['body'];
            if (! is_array($body)) {
                throw new RuntimeException(__('settings.acme_order_failed'));
            }
            $orderStatus = (string) ($body['status'] ?? '');
            if ($orderStatus === 'valid' && ! empty($body['certificate'])) {
                $certUrl = (string) $body['certificate'];
                break;
            }
            if (in_array($orderStatus, ['invalid', 'revoked', 'expired'], true)) {
                throw new RuntimeException(__('settings.acme_order_failed').': '.$orderStatus);
            }
            usleep(1_000_000);
        }
        if ($certUrl === null) {
            throw new RuntimeException(__('settings.acme_challenge_timeout'));
        }

        $certResp = $client->signedRequest($certUrl, null, useKid: true);
        $pemChain = is_string($certResp['body']) ? $certResp['body'] : '';
        if ($pemChain === '' || ! str_contains($pemChain, 'BEGIN CERTIFICATE')) {
            // Some servers return JSON error; retry as raw GET with kid POST-as-GET already done
            throw new RuntimeException(__('settings.cert_files_not_found_after_issue'));
        }

        $liveDir = $this->baseDir().'/certs/live/panel';
        if (! is_dir($liveDir)) {
            mkdir($liveDir, 0755, true);
        }
        file_put_contents($liveDir.'/fullchain.pem', $pemChain);
        file_put_contents($liveDir.'/privkey.pem', $domainKeyPem);
        @chmod($liveDir.'/privkey.pem', 0640);

        $panelDir = $this->baseDir().'/certs/panel';
        if (! is_dir($panelDir)) {
            mkdir($panelDir, 0755, true);
        }
        file_put_contents($panelDir.'/fullchain.pem', $pemChain);
        file_put_contents($panelDir.'/privkey.pem', $domainKeyPem);
        @chmod($panelDir.'/privkey.pem', 0640);

        $this->clearPendingFiles();

        return [
            'fullchain' => $panelDir.'/fullchain.pem',
            'privkey' => $panelDir.'/privkey.pem',
        ];
    }

    public function abort(): void
    {
        $this->clearPendingFiles();
    }

    /**
     * @return array{txt_name:string,txt_value:string,domain:string}|null
     */
    private function readChallengeFiles(): ?array
    {
        $dir = $this->challengeDir();
        $ready = $dir.'/ready';
        $validation = $dir.'/validation';
        $domainFile = $dir.'/domain';

        if (! is_file($ready) || ! is_readable($validation) || ! is_readable($domainFile)) {
            return null;
        }

        $domain = strtolower(trim((string) file_get_contents($domainFile)));
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

    private function writeChallengeFiles(string $domain, string $txtValue): void
    {
        $dir = $this->challengeDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir.'/domain', $domain);
        file_put_contents($dir.'/validation', $txtValue);
        @unlink($dir.'/done');
        @unlink($dir.'/abort');
        touch($dir.'/ready');
    }

    private function backfillTxtValueInOrder(string $domain, string $txtValue): void
    {
        $path = $this->acmeDir().'/pending/order.json';
        if (! is_readable($path)) {
            return;
        }
        try {
            $pending = $this->loadPending();
        } catch (RuntimeException) {
            return;
        }
        if (($pending['domain'] ?? '') !== $domain) {
            return;
        }
        if (trim((string) ($pending['txt_value'] ?? '')) === $txtValue
            && trim((string) ($pending['txt_name'] ?? '')) !== '') {
            return;
        }
        $pending['txt_value'] = $txtValue;
        $pending['txt_name'] = '_acme-challenge.'.$domain;
        file_put_contents($path, json_encode($pending, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function clearPendingFiles(): void
    {
        foreach ([
            $this->acmeDir().'/pending/order.json',
            $this->acmeDir().'/pending/domain.pem',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        foreach (['ready', 'done', 'abort', 'domain', 'validation', 'failed'] as $name) {
            $path = $this->challengeDir().'/'.$name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** @return array<string, mixed> */
    private function loadPending(): array
    {
        $raw = file_get_contents($this->acmeDir().'/pending/order.json');
        $data = json_decode((string) $raw, true);
        if (! is_array($data)) {
            throw new RuntimeException(__('settings.no_active_dns_challenge'));
        }

        return $data;
    }

    private function client(): AcmeHttpClient
    {
        $this->ensureLayout();
        $keyPath = $this->acmeDir().'/account/account.pem';
        if (! is_readable($keyPath)) {
            $pem = $this->generateRsaPrivateKeyPem();
            file_put_contents($keyPath, $pem);
            @chmod($keyPath, 0600);
        }
        $pem = (string) file_get_contents($keyPath);

        return new AcmeHttpClient($pem, $this->directoryUrl);
    }

    private function ensureAccount(AcmeHttpClient $client, string $email): void
    {
        if ($client->accountUrl()) {
            return;
        }

        $accountFile = $this->acmeDir().'/account/account_url';
        if (is_readable($accountFile)) {
            $url = trim((string) file_get_contents($accountFile));
            if ($url !== '') {
                $client->setAccountUrl($url);

                return;
            }
        }

        $newAccount = $client->resourceUrl('newAccount');
        try {
            $resp = $client->signedRequest($newAccount, [
                'termsOfServiceAgreed' => true,
                'contact' => $email !== '' ? ['mailto:'.$email] : [],
            ], useKid: false);
        } catch (RuntimeException $e) {
            // Account may already exist for this key
            $resp = $client->signedRequest($newAccount, [
                'onlyReturnExisting' => true,
            ], useKid: false);
        }

        $location = $resp['location'] ?? null;
        if (! is_string($location) || $location === '') {
            throw new RuntimeException(__('settings.acme_account_failed'));
        }
        $client->setAccountUrl($location);
        file_put_contents($accountFile, $location);
    }

    private function generateRsaPrivateKeyPem(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($key === false) {
            throw new RuntimeException('Failed to generate RSA key');
        }
        $pem = '';
        if (! openssl_pkey_export($key, $pem) || $pem === '') {
            throw new RuntimeException('Failed to export RSA key');
        }

        return $pem;
    }

    private function createCsrDer(string $domain, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('Invalid domain private key');
        }

        // Let's Encrypt requires subjectAltName; always build CSR with an openssl.cnf.
        $csr = $this->createCsrWithSan($domain, $key);
        if ($csr === false) {
            throw new RuntimeException('Failed to create CSR');
        }

        $pem = '';
        if (! openssl_csr_export($csr, $pem) || $pem === '') {
            throw new RuntimeException('Failed to export CSR');
        }

        $der = $this->pemToDer($pem);
        if ($der === '') {
            throw new RuntimeException('Failed to decode CSR DER');
        }

        return $der;
    }

    /** @param  \OpenSSLAsymmetricKey|resource  $key */
    private function createCsrWithSan(string $domain, $key)
    {
        $config = sys_get_temp_dir().'/awg-acme-openssl-'.uniqid('', true).'.cnf';
        $content = <<<CNF
[ req ]
distinguished_name = req_distinguished_name
req_extensions = v3_req
prompt = no

[ req_distinguished_name ]
CN = {$domain}

[ v3_req ]
subjectAltName = @alt_names

[ alt_names ]
DNS.1 = {$domain}
CNF;
        file_put_contents($config, $content);
        try {
            return openssl_csr_new(['commonName' => $domain], $key, [
                'digest_alg' => 'sha256',
                'config' => $config,
                'req_extensions' => 'v3_req',
            ]);
        } finally {
            @unlink($config);
        }
    }

    private function pemToDer(string $pem): string
    {
        $b64 = preg_replace('/-----BEGIN [^-]+-----/', '', $pem) ?? '';
        $b64 = preg_replace('/-----END [^-]+-----/', '', $b64) ?? '';
        $b64 = preg_replace('/\s+/', '', $b64) ?? '';

        return (string) base64_decode($b64, true);
    }
}
