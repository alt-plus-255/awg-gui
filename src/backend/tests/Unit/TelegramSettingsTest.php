<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_is_admin_matches_configured_id(): void
    {
        Setting::setValue('telegram_admin_id', '12345');
        $settings = app(TelegramSettings::class);

        $this->assertTrue($settings->isAdmin(12345));
        $this->assertTrue($settings->isAdmin('12345'));
        $this->assertFalse($settings->isAdmin(999));
    }

    public function test_proxies_parse_url_and_connection(): void
    {
        Setting::setValue('telegram_proxies', json_encode([
            ['id' => 'a', 'type' => 'url', 'url' => 'socks5://u:p@host:1080', 'enabled' => true],
            ['id' => 'b', 'type' => 'connection', 'connection_id' => 7, 'enabled' => true],
            ['id' => 'c', 'type' => 'url', 'url' => '', 'enabled' => true],
        ]));

        $settings = app(TelegramSettings::class);
        $proxies = $settings->proxies();

        $this->assertCount(2, $proxies);
        $this->assertSame([7], $settings->enabledConnectionIds());
        $this->assertTrue($settings->hasEnabledConnectionProxies());
    }

    public function test_mask_token(): void
    {
        $settings = app(TelegramSettings::class);
        $masked = $settings->maskToken('123456:ABC-DEF');
        $this->assertStringContainsString('*', $masked);
        $this->assertStringNotContainsString('ABC-DEF', $masked);
    }

    public function test_for_api_masks_webhook_secret(): void
    {
        Setting::setValue('telegram_webhook_secret', 'real-webhook-secret');
        $payload = app(TelegramSettings::class)->forApi();

        $this->assertSame('********', $payload['telegram_webhook_secret']);
        $this->assertTrue($payload['telegram_webhook_secret_set']);
        $this->assertSame('real-webhook-secret', Setting::getValue('telegram_webhook_secret'));
    }

    public function test_mixed_proxy_url_includes_auth(): void
    {
        $settings = app(TelegramSettings::class);
        $url = $settings->mixedProxyUrl();
        $auth = $settings->mixedAuth();

        $this->assertStringContainsString('socks5h://', $url);
        $this->assertStringContainsString(rawurlencode($auth['username']), $url);
        $this->assertStringNotContainsString('socks5h://awg:', $url);
        $this->assertSame($auth['username'], Setting::getValue('telegram_mixed_auth_user'));
        $this->assertSame($auth['password'], Setting::getValue('telegram_mixed_auth_pass'));
    }

    public function test_ensure_mixed_auth_creates_once(): void
    {
        $settings = app(TelegramSettings::class);

        $this->assertTrue($settings->ensureMixedAuth());
        $user = Setting::getValue('telegram_mixed_auth_user');
        $pass = Setting::getValue('telegram_mixed_auth_pass');
        $this->assertNotSame('', $user);
        $this->assertNotSame('', $pass);

        $this->assertFalse($settings->ensureMixedAuth());
        $this->assertSame($user, Setting::getValue('telegram_mixed_auth_user'));
        $this->assertSame($pass, Setting::getValue('telegram_mixed_auth_pass'));
    }

    public function test_daily_report_defaults_enabled(): void
    {
        $settings = app(TelegramSettings::class);
        $this->assertTrue($settings->dailyReportEnabled());

        Setting::setValue('telegram_daily_report_enabled', '0');
        $this->assertFalse($settings->dailyReportEnabled());
    }
}
