<?php

namespace Tests\Unit;

use App\Services\Ssl\AcmeDnsIssuer;
use App\Services\Ssl\AcmeHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AcmeDnsIssuerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/awg-acme-test-'.uniqid('', true);
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
        parent::tearDown();
    }

    public function test_start_writes_dns_challenge_files(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, 'directory')) {
                return Http::response([
                    'newNonce' => 'https://acme.test/new-nonce',
                    'newAccount' => 'https://acme.test/new-account',
                    'newOrder' => 'https://acme.test/new-order',
                ], 200);
            }

            if ($method === 'HEAD' && str_contains($url, 'new-nonce')) {
                return Http::response('', 200, ['Replay-Nonce' => 'nonce-1']);
            }

            if (str_contains($url, 'new-account')) {
                return Http::response(['status' => 'valid'], 201, [
                    'Location' => 'https://acme.test/acct/1',
                    'Replay-Nonce' => 'nonce-2',
                ]);
            }

            if (str_contains($url, 'new-order')) {
                return Http::response([
                    'status' => 'pending',
                    'finalize' => 'https://acme.test/finalize/1',
                    'authorizations' => ['https://acme.test/authz/1'],
                ], 201, [
                    'Location' => 'https://acme.test/order/1',
                    'Replay-Nonce' => 'nonce-3',
                ]);
            }

            if (str_contains($url, 'authz/1')) {
                return Http::response([
                    'status' => 'pending',
                    'identifier' => ['type' => 'dns', 'value' => 'panel.example.com'],
                    'challenges' => [
                        [
                            'type' => 'dns-01',
                            'url' => 'https://acme.test/chal/1',
                            'token' => 'test-token-abc',
                            'status' => 'pending',
                        ],
                    ],
                ], 200, ['Replay-Nonce' => 'nonce-4']);
            }

            return Http::response(['detail' => 'unexpected '.$url], 500);
        });

        $issuer = new AcmeDnsIssuer($this->root, 'https://acme.test/directory');
        $challenge = $issuer->start('panel.example.com', 'admin@example.com');

        $this->assertSame('panel.example.com', $challenge['domain']);
        $this->assertSame('_acme-challenge.panel.example.com', $challenge['txt_name']);
        $this->assertNotSame('', $challenge['txt_value']);
        $this->assertTrue($issuer->hasPendingOrder());
        $this->assertSame($challenge, $issuer->readPendingChallenge());
    }

    public function test_abort_clears_pending_challenge(): void
    {
        Http::fake(function ($request) {
            $url = (string) $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, 'directory')) {
                return Http::response([
                    'newNonce' => 'https://acme.test/new-nonce',
                    'newAccount' => 'https://acme.test/new-account',
                    'newOrder' => 'https://acme.test/new-order',
                ], 200);
            }
            if ($method === 'HEAD') {
                return Http::response('', 200, ['Replay-Nonce' => 'n1']);
            }
            if (str_contains($url, 'new-account')) {
                return Http::response(['status' => 'valid'], 201, [
                    'Location' => 'https://acme.test/acct/1',
                    'Replay-Nonce' => 'n2',
                ]);
            }
            if (str_contains($url, 'new-order')) {
                return Http::response([
                    'status' => 'pending',
                    'finalize' => 'https://acme.test/finalize/1',
                    'authorizations' => ['https://acme.test/authz/1'],
                ], 201, [
                    'Location' => 'https://acme.test/order/1',
                    'Replay-Nonce' => 'n3',
                ]);
            }
            if (str_contains($url, 'authz/1')) {
                return Http::response([
                    'status' => 'pending',
                    'identifier' => ['type' => 'dns', 'value' => 'panel.example.com'],
                    'challenges' => [[
                        'type' => 'dns-01',
                        'url' => 'https://acme.test/chal/1',
                        'token' => 'tok',
                        'status' => 'pending',
                    ]],
                ], 200, ['Replay-Nonce' => 'n4']);
            }

            return Http::response(['detail' => 'unexpected'], 500);
        });

        $issuer = new AcmeDnsIssuer($this->root, 'https://acme.test/directory');
        $issuer->start('panel.example.com', 'admin@example.com');
        $issuer->abort();

        $this->assertNull($issuer->readPendingChallenge());
        $this->assertFalse($issuer->hasPendingOrder());
    }

    public function test_complete_writes_panel_pem_files(): void
    {
        $pemLeaf = $this->selfSignedPem('panel.example.com');

        Http::fake(function ($request) use ($pemLeaf) {
            $url = (string) $request->url();
            $method = $request->method();

            if ($method === 'GET' && str_contains($url, 'directory')) {
                return Http::response([
                    'newNonce' => 'https://acme.test/new-nonce',
                    'newAccount' => 'https://acme.test/new-account',
                    'newOrder' => 'https://acme.test/new-order',
                ], 200);
            }
            if ($method === 'HEAD') {
                return Http::response('', 200, ['Replay-Nonce' => 'n1']);
            }
            if (str_contains($url, 'new-account')) {
                return Http::response(['status' => 'valid'], 201, [
                    'Location' => 'https://acme.test/acct/1',
                    'Replay-Nonce' => 'n2',
                ]);
            }
            if (str_contains($url, 'new-order')) {
                return Http::response([
                    'status' => 'pending',
                    'finalize' => 'https://acme.test/finalize/1',
                    'authorizations' => ['https://acme.test/authz/1'],
                ], 201, [
                    'Location' => 'https://acme.test/order/1',
                    'Replay-Nonce' => 'n3',
                ]);
            }
            if (str_contains($url, 'authz/1')) {
                static $authCalls = 0;
                $authCalls++;
                $status = $authCalls === 1 ? 'pending' : 'valid';

                return Http::response([
                    'status' => $status,
                    'identifier' => ['type' => 'dns', 'value' => 'panel.example.com'],
                    'challenges' => [[
                        'type' => 'dns-01',
                        'url' => 'https://acme.test/chal/1',
                        'token' => 'tok',
                        'status' => $status,
                    ]],
                ], 200, ['Replay-Nonce' => 'n-auth-'.$authCalls]);
            }
            if (str_contains($url, 'chal/1')) {
                return Http::response(['status' => 'processing'], 200, ['Replay-Nonce' => 'n-chal']);
            }
            if (str_contains($url, 'finalize/1')) {
                return Http::response(['status' => 'processing'], 200, ['Replay-Nonce' => 'n-fin']);
            }
            if (str_contains($url, 'order/1')) {
                return Http::response([
                    'status' => 'valid',
                    'certificate' => 'https://acme.test/cert/1',
                    'finalize' => 'https://acme.test/finalize/1',
                ], 200, ['Replay-Nonce' => 'n-ord']);
            }
            if (str_contains($url, 'cert/1')) {
                return Http::response($pemLeaf, 200, [
                    'Content-Type' => 'application/pem-certificate-chain',
                    'Replay-Nonce' => 'n-cert',
                ]);
            }

            return Http::response(['detail' => 'unexpected '.$url], 500);
        });

        $issuer = new AcmeDnsIssuer($this->root, 'https://acme.test/directory');
        $issuer->start('panel.example.com', 'admin@example.com');
        $paths = $issuer->complete(timeoutSeconds: 5);

        $this->assertFileExists($paths['fullchain']);
        $this->assertFileExists($paths['privkey']);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) file_get_contents($paths['fullchain']));
        $this->assertStringContainsString('BEGIN PRIVATE KEY', (string) file_get_contents($paths['privkey']));
        $this->assertNull($issuer->readPendingChallenge());
    }

    public function test_http_client_dns_txt_is_sha256_of_key_authorization(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $pem = '';
        openssl_pkey_export($key, $pem);
        $client = new AcmeHttpClient($pem, 'https://acme.test/directory');
        $token = 'abc';
        $expected = rtrim(strtr(base64_encode(hash('sha256', $client->keyAuthorization($token), true)), '+/', '-_'), '=');
        $this->assertSame($expected, $client->dnsTxtValue($token));
    }

    private function selfSignedPem(string $cn): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        $pem = '';
        openssl_x509_export($cert, $pem);

        return $pem;
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
