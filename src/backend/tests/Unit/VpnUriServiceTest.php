<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\QrCodeService;
use App\Services\AmneziaWg\VpnUriService;
use PHPUnit\Framework\TestCase;

class VpnUriServiceTest extends TestCase
{
    private VpnUriService $service;

    private string $sampleConf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampleConf = <<<'CONF'
[Interface]
PrivateKey = gHwRoR0M6ib7etZW193COEMhQgDoKO6NTniQkJ+UgxX8=
Jc = 4
Jmin = 64
Jmax = 80
S1 = 0
S2 = 0
S3 = 0
S4 = 0
H1 = 1
H2 = 2
H3 = 3
H4 = 4
Address = 10.66.66.2/32
DNS = 1.1.1.1, 1.0.0.1

[Peer]
PublicKey = yJ8xK2vN3mP4qR5sT6uV7wX8yZ9aB0cD1eF2gH3iJ4k=
AllowedIPs = 0.0.0.0/0, ::/0
Endpoint = vpn.example.com:51820
PersistentKeepalive = 25

CONF;

        $awg = $this->createMock(AmneziaWgService::class);
        $awg->method('buildClientConfig')->willReturn($this->sampleConf);

        $this->service = new VpnUriService($awg, new QrCodeService);
    }

    public function test_build_from_membership_returns_vpn_uri_prefix(): void
    {
        $uri = $this->service->buildFromMembership($this->membership());

        $this->assertStringStartsWith('vpn://', $uri);
        $this->assertGreaterThan(100, strlen($uri));
    }

    public function test_decode_round_trip(): void
    {
        $uri = $this->service->buildFromMembership($this->membership());
        $outer = $this->service->decode($uri);

        $this->assertSame('amnezia-awg', $outer['defaultContainer']);
        $this->assertSame('vpn.example.com', $outer['hostName']);
        $this->assertSame('1.1.1.1', $outer['dns1']);
        $this->assertSame('1.0.0.1', $outer['dns2']);

        $lastConfig = json_decode($outer['containers'][0]['awg']['last_config'], true);
        $this->assertIsArray($lastConfig);
        $this->assertSame('10.66.66.2/32', $lastConfig['client_ip']);
        $this->assertSame(51820, $lastConfig['port']);
        $this->assertSame(['0.0.0.0/0', '::/0'], $lastConfig['allowed_ips']);
    }

    public function test_last_config_contains_original_conf_text(): void
    {
        $uri = $this->service->buildFromMembership($this->membership());
        $outer = $this->service->decode($uri);
        $lastConfig = json_decode($outer['containers'][0]['awg']['last_config'], true);

        $expected = rtrim((new QrCodeService)->normalizeConfigText($this->sampleConf), "\n");
        $this->assertSame($expected, $lastConfig['config']);
    }

    public function test_single_resolver_dns_uses_same_dns1_and_dns2(): void
    {
        $resolverConf = str_replace('DNS = 1.1.1.1, 1.0.0.1', 'DNS = 10.66.66.1', $this->sampleConf);

        $awg = $this->createMock(AmneziaWgService::class);
        $awg->method('buildClientConfig')->willReturn($resolverConf);

        $service = new VpnUriService($awg, new QrCodeService);
        $outer = $service->decode($service->buildFromMembership($this->membership()));

        $this->assertSame('10.66.66.1', $outer['dns1']);
        $this->assertSame('10.66.66.1', $outer['dns2']);
    }

    public function test_qr_png_encodes_conf_text(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for QR PNG generation');
        }

        $conf = (new QrCodeService)->normalizeConfigText($this->sampleConf);
        $png = (new QrCodeService)->buildPng($conf);

        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        if (! $this->zbarAvailable()) {
            $this->markTestSkipped('zbarimg is required to decode QR payload');
        }

        $tmpPng = tempnam(sys_get_temp_dir(), 'conf-qr-');
        $tmpTxt = tempnam(sys_get_temp_dir(), 'conf-qr-out-');
        file_put_contents($tmpPng, $png);

        $process = proc_open(
            ['zbarimg', '--raw', '-q', $tmpPng],
            [1 => ['file', $tmpTxt, 'w']],
            $pipes
        );
        proc_close($process);

        $decoded = trim((string) file_get_contents($tmpTxt));
        @unlink($tmpPng);
        @unlink($tmpTxt);

        $this->assertStringContainsString('[Interface]', $decoded);
        $this->assertStringContainsString('Jc = 4', $decoded);
        $this->assertStringContainsString('H4 = 4', $decoded);
        $this->assertSame($conf, $decoded);
    }

    private function membership(): AwgConfigPeer
    {
        $config = new AwgConfig([
            'name' => 'test',
            'listen_port' => 51820,
            'server_public_key' => 'yJ8xK2vN3mP4qR5sT6uV7wX8yZ9aB0cD1eF2gH3iJ4k=',
            'jc' => 4,
            'jmin' => 64,
            'jmax' => 80,
            's1' => 0,
            's2' => 0,
            's3' => 0,
            's4' => 0,
            'h1' => 1,
            'h2' => 2,
            'h3' => 3,
            'h4' => 4,
        ]);

        $client = new VpnClient(['name' => 'alice']);

        $membership = new AwgConfigPeer([
            'private_key' => 'gHwRoR0M6ib7etZW193COEMhQgDoKO6NTniQkJ+UgxX8=',
            'address' => '10.66.66.2/32',
            'keepalive' => 25,
        ]);

        $membership->setRelation('config', $config);
        $membership->setRelation('client', $client);

        return $membership;
    }

    private function zbarAvailable(): bool
    {
        $process = proc_open(['sh', '-c', 'command -v zbarimg'], [], $pipes);
        $code = proc_close($process);

        return $code === 0;
    }
}
