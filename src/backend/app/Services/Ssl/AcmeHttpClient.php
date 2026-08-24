<?php

namespace App\Services\Ssl;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal ACME v2 HTTP helper (JWS-signed requests) for Let's Encrypt DNS-01.
 */
class AcmeHttpClient
{
    private string $directoryUrl;

    /** @var array<string, string>|null */
    private ?array $directory = null;

    private ?string $nonce = null;

    private string $accountKeyPem;

    private ?string $accountUrl = null;

    public function __construct(string $accountKeyPem, ?string $directoryUrl = null)
    {
        $this->accountKeyPem = $accountKeyPem;
        $this->directoryUrl = $directoryUrl
            ?: (string) env('ACME_DIRECTORY_URL', 'https://acme-v02.api.letsencrypt.org/directory');
    }

    public function setAccountUrl(?string $url): void
    {
        $this->accountUrl = $url;
    }

    public function accountUrl(): ?string
    {
        return $this->accountUrl;
    }

    public function directoryUrl(): string
    {
        return $this->directoryUrl;
    }

    /** @return array<string, mixed> */
    public function directory(): array
    {
        if ($this->directory === null) {
            $response = Http::timeout(30)->get($this->directoryUrl);
            if (! $response->successful()) {
                throw new RuntimeException('ACME directory request failed: HTTP '.$response->status());
            }
            $this->directory = $response->json();
            if (! is_array($this->directory)) {
                throw new RuntimeException('Invalid ACME directory response');
            }
        }

        return $this->directory;
    }

    public function resourceUrl(string $name): string
    {
        $dir = $this->directory();
        if (empty($dir[$name]) || ! is_string($dir[$name])) {
            throw new RuntimeException("ACME directory missing resource: {$name}");
        }

        return $dir[$name];
    }

    /**
     * @param  array<string, mixed>|\stdClass|null  $payload  null = POST-as-GET; stdClass = {}
     * @return array{status:int, headers:array<string, list<string>>, body:array<string, mixed>|string, location:?string}
     */
    public function signedRequest(string $url, array|\stdClass|null $payload, bool $useKid = true): array
    {
        $this->ensureNonce();

        $protected = [
            'alg' => 'RS256',
            'nonce' => $this->nonce,
            'url' => $url,
        ];

        if ($useKid && $this->accountUrl) {
            $protected['kid'] = $this->accountUrl;
        } else {
            $protected['jwk'] = $this->jwk();
        }

        $protectedB64 = $this->b64((string) json_encode($protected, JSON_UNESCAPED_SLASHES));
        if ($payload === null) {
            $payloadB64 = $this->b64('');
        } elseif ($payload instanceof \stdClass) {
            $payloadB64 = $this->b64('{}');
        } else {
            $payloadB64 = $this->b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        $signatureB64 = $this->sign("{$protectedB64}.{$payloadB64}");

        $jose = (string) json_encode([
            'protected' => $protectedB64,
            'payload' => $payloadB64,
            'signature' => $signatureB64,
        ], JSON_UNESCAPED_SLASHES);

        $response = Http::timeout(60)
            ->withHeaders([
                'Content-Type' => 'application/jose+json',
                'Accept' => 'application/pem-certificate-chain, application/json',
            ])
            ->withBody($jose, 'application/jose+json')
            ->send('POST', $url);

        if ($response->header('Replay-Nonce')) {
            $this->nonce = $response->header('Replay-Nonce');
        }

        $location = $response->header('Location');
        $raw = $response->body();
        $json = json_decode($raw, true);
        $body = is_array($json) ? $json : $raw;

        if ($response->status() >= 400) {
            $detail = is_array($body)
                ? (string) ($body['detail'] ?? $body['type'] ?? $raw)
                : $raw;
            throw new RuntimeException('ACME request failed ('.$response->status().'): '.$detail);
        }

        return [
            'status' => $response->status(),
            'headers' => [
                'location' => $location ? [$location] : [],
            ],
            'body' => $body,
            'location' => $location ?: null,
        ];
    }

    /** @return array<string, string> */
    public function jwk(): array
    {
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->accountKeyPem));
        if ($details === false || empty($details['rsa'])) {
            throw new RuntimeException('Invalid ACME account private key');
        }

        return [
            'e' => $this->b64($details['rsa']['e']),
            'kty' => 'RSA',
            'n' => $this->b64($details['rsa']['n']),
        ];
    }

    public function thumbprint(): string
    {
        $jwk = $this->jwk();
        // RFC 7638 key order: e, kty, n
        $json = '{"e":"'.$jwk['e'].'","kty":"RSA","n":"'.$jwk['n'].'"}';

        return $this->b64(hash('sha256', $json, true));
    }

    public function keyAuthorization(string $token): string
    {
        return $token.'.'.$this->thumbprint();
    }

    public function dnsTxtValue(string $token): string
    {
        return $this->b64(hash('sha256', $this->keyAuthorization($token), true));
    }

    private function ensureNonce(): void
    {
        if ($this->nonce !== null && $this->nonce !== '') {
            return;
        }

        $newNonce = $this->resourceUrl('newNonce');
        $response = Http::timeout(30)->head($newNonce);
        $nonce = $response->header('Replay-Nonce');
        if (! is_string($nonce) || $nonce === '') {
            // Some servers only set nonce on GET
            $response = Http::timeout(30)->get($newNonce);
            $nonce = $response->header('Replay-Nonce');
        }
        if (! is_string($nonce) || $nonce === '') {
            throw new RuntimeException('Failed to obtain ACME nonce');
        }
        $this->nonce = $nonce;
    }

    private function sign(string $input): string
    {
        $key = openssl_pkey_get_private($this->accountKeyPem);
        if ($key === false) {
            throw new RuntimeException('Cannot load ACME account key');
        }
        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('ACME JWS signature failed');
        }

        return $this->b64($signature);
    }

    public function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
