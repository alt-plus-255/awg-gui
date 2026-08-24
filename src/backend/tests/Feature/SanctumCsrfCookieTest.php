<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SanctumCsrfCookieTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_cookie_sets_session_for_https_domain_host(): void
    {
        Setting::setValue('panel_domain', 'vpn.example.com');
        Setting::setValue('panel_https_port', '7443');
        Setting::setValue('ssl_enabled', '1');

        // Stale env-style list without the panel domain (simulates post-update .env).
        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);

        $response = $this->withServerVariables([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'vpn.example.com:7443',
        ])->get('/sanctum/csrf-cookie');

        $response->assertNoContent();
        $response->assertCookie(config('session.cookie'));

        $stateful = config('sanctum.stateful', []);
        $this->assertContains('vpn.example.com:7443', $stateful);
        $this->assertContains('vpn.example.com', $stateful);
    }

    public function test_resolve_sanctum_stateful_domains_includes_panel_https_host(): void
    {
        Setting::setValue('panel_domain', 'vpn.example.com');
        Setting::setValue('panel_port', '8877');
        Setting::setValue('panel_https_port', '7443');

        $domains = app(\App\Services\AmneziaWg\AmneziaWgService::class)
            ->resolveSanctumStatefulDomains();

        $this->assertContains('vpn.example.com', $domains);
        $this->assertContains('vpn.example.com:8877', $domains);
        $this->assertContains('vpn.example.com:7443', $domains);
        $this->assertContains(\Laravel\Sanctum\Sanctum::$currentRequestHostPlaceholder, $domains);
    }
}
