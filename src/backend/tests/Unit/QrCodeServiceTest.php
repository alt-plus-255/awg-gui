<?php

namespace Tests\Unit;

use App\Services\AmneziaWg\QrCodeService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class QrCodeServiceTest extends TestCase
{
    private QrCodeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QrCodeService;
    }

    public function test_normalize_config_text_removes_empty_signature_fields(): void
    {
        $conf = <<<'CONF'
[Interface]
PrivateKey = abc=
Address = 10.66.66.2/32
I2 =
I3 =
I4 =   
H1 = 1

[Peer]
PublicKey = xyz=
CONF;

        $normalized = $this->service->normalizeConfigText($conf);

        $this->assertStringNotContainsString("I2 =\n", $normalized);
        $this->assertStringNotContainsString("I3 =\n", $normalized);
        $this->assertStringNotContainsString("I4 =\n", $normalized);
        $this->assertStringContainsString('H1 = 1', $normalized);
        $this->assertStringEndsWith("\n", $normalized);
    }

    public function test_build_png_returns_valid_png_for_conf_text(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for QR PNG generation');
        }

        $conf = $this->service->normalizeConfigText(<<<'CONF'
[Interface]
PrivateKey = abc=
Jc = 4
Jmin = 64
Jmax = 80
H1 = 1
Address = 10.66.66.2/32

[Peer]
PublicKey = xyz=
Endpoint = vpn.example.com:51820
CONF);
        $png = $this->service->buildPng($conf);

        $this->assertNotEmpty($png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
    }

    public function test_build_svg_returns_svg_for_vpn_uri(): void
    {
        if (! $this->qrencodeAvailable()) {
            $this->markTestSkipped('qrencode is required for SVG QR generation');
        }

        $uri = 'vpn://'.str_repeat('AbCdEfGh', 20);
        $svg = $this->service->buildSvg($uri);

        $this->assertStringContainsString('<svg', $svg);
    }

    private function qrencodeAvailable(): bool
    {
        $process = new Process(['sh', '-c', 'command -v qrencode']);
        $process->setTimeout(2);
        $process->run();

        return $process->isSuccessful();
    }
}
