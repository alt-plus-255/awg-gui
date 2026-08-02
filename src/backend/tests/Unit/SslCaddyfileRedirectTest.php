<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\SslCertificateService;
use App\Services\Docker\DockerRuntime;
use App\Services\Docker\PanelOpsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SslCaddyfileRedirectTest extends TestCase
{
    use RefreshDatabase;

    private function sslService(): SslCertificateService
    {
        return new SslCertificateService(
            app(AmneziaWgService::class),
            Mockery::mock(DockerRuntime::class),
            Mockery::mock(PanelOpsClient::class),
        );
    }

    public function test_ssl_caddyfile_does_not_force_ip_redirect_by_default(): void
    {
        Setting::setValue('panel_domain', 'vpn.example.com');
        Setting::setValue('panel_https_port', '7443');
        Setting::setValue('redirect_ip_to_domain', '0');

        $caddy = $this->sslService()->caddyfileContents(true);

        $this->assertStringContainsString('@panel host vpn.example.com', $caddy);
        $this->assertStringContainsString('redir @panel https://vpn.example.com:7443{uri} temporary', $caddy);
        $this->assertStringNotContainsString('@not_panel', $caddy);
        $this->assertFalse(app(AmneziaWgService::class)->shouldRedirectIpToDomain());
    }

    public function test_ssl_caddyfile_forces_ip_redirect_when_enabled(): void
    {
        Setting::setValue('panel_domain', 'vpn.example.com');
        Setting::setValue('panel_https_port', '7443');
        Setting::setValue('redirect_ip_to_domain', '1');

        $caddy = $this->sslService()->caddyfileContents(true);

        $this->assertStringContainsString('@panel host vpn.example.com', $caddy);
        $this->assertStringContainsString('@not_panel not host vpn.example.com', $caddy);
        $this->assertStringContainsString('redir @not_panel https://vpn.example.com:7443{uri} temporary', $caddy);
        $this->assertTrue(app(AmneziaWgService::class)->shouldRedirectIpToDomain());
    }

    public function test_redirect_ip_to_domain_requires_domain(): void
    {
        Setting::setValue('panel_domain', '');
        Setting::setValue('redirect_ip_to_domain', '1');

        $this->assertFalse(app(AmneziaWgService::class)->shouldRedirectIpToDomain());
    }
}
