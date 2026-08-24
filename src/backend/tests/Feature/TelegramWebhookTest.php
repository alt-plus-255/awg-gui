<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'good-secret';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function postWebhook(array $payload, ?string $pathSecret = self::WEBHOOK_SECRET, ?string $headerSecret = self::WEBHOOK_SECRET)
    {
        $headers = [];
        if ($headerSecret !== null) {
            $headers['X-Telegram-Bot-Api-Secret-Token'] = $headerSecret;
        }

        return $this->postJson('/api/telegram/webhook/'.($pathSecret ?? 'missing'), $payload, $headers);
    }

    private function configureWebhookBot(string $adminId = '42'): void
    {
        Setting::setValue('telegram_webhook_secret', self::WEBHOOK_SECRET);
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', $adminId);
        Setting::setValue('telegram_mode', 'webhook');
    }

    public function test_webhook_rejects_bad_path_secret(): void
    {
        $this->configureWebhookBot('1');

        $this->postWebhook([
            'update_id' => 1,
            'message' => ['text' => '/start', 'chat' => ['id' => 1], 'from' => ['id' => 1]],
        ], pathSecret: 'bad-secret')->assertNotFound();
    }

    public function test_webhook_rejects_missing_secret_header(): void
    {
        $this->configureWebhookBot('1');

        $this->postWebhook([
            'update_id' => 1,
            'message' => ['text' => '/start', 'chat' => ['id' => 1], 'from' => ['id' => 1]],
        ], headerSecret: null)->assertNotFound();
    }

    public function test_webhook_rejects_bad_secret_header(): void
    {
        $this->configureWebhookBot('1');

        $this->postWebhook([
            'update_id' => 1,
            'message' => ['text' => '/start', 'chat' => ['id' => 1], 'from' => ['id' => 1]],
        ], headerSecret: 'wrong-header')->assertNotFound();
    }

    public function test_webhook_non_admin_start_gets_forbidden(): void
    {
        $this->configureWebhookBot('42');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->postWebhook([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'text' => '/start',
                'chat' => ['id' => 999],
                'from' => ['id' => 999],
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'sendMessage')
                && ($data['text'] ?? '') === __('telegram.forbidden');
        });
    }

    public function test_admin_start_sends_dashboard_home(): void
    {
        $this->configureWebhookBot('42');

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]]),
        ]);

        $this->postWebhook([
            'update_id' => 2,
            'message' => [
                'message_id' => 2,
                'text' => '/start',
                'chat' => ['id' => 42],
                'from' => ['id' => 42],
            ],
        ])->assertOk()->assertJson(['ok' => true]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'sendMessage')) {
                return false;
            }
            $data = $request->data();
            $text = (string) ($data['text'] ?? '');

            return str_contains($text, __('telegram.home_title'))
                && str_contains($text, (string) __('telegram.dashboard_summary', [
                    'peers' => 0,
                    'enabled' => 0,
                    'online' => 0,
                ]))
                && str_contains($text, 'CPU:')
                && isset($data['reply_markup']);
        });
    }

    public function test_settings_update_accepts_telegram_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Setting::setValue('telegram_webhook_secret', 'sec');

        $response = $this->putJson('/api/settings', [
            'telegram_admin_id' => '777',
            'telegram_mode' => 'polling',
            'telegram_language' => 'ru',
            'telegram_proxy_strategy' => 'first_ok',
            'telegram_notifications_enabled' => true,
            'telegram_proxies' => [
                ['id' => 'p1', 'type' => 'url', 'url' => 'socks5://127.0.0.1:1080', 'enabled' => true],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('777', Setting::getValue('telegram_admin_id'));
        $this->assertSame('polling', Setting::getValue('telegram_mode'));
        $this->assertSame('ru', Setting::getValue('telegram_language'));
        $this->assertSame('first_ok', Setting::getValue('telegram_proxy_strategy'));
        $this->assertStringContainsString('socks5://127.0.0.1:1080', (string) Setting::getValue('telegram_proxies'));
    }

    public function test_settings_show_masks_webhook_secret(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Setting::setValue('telegram_webhook_secret', 'super-secret-value-xyz');
        Setting::setValue('telegram_bot_token', '');
        Setting::setValue('telegram_admin_id', '');

        $response = $this->getJson('/api/settings')->assertOk();
        $settings = $response->json('settings');

        $this->assertSame('********', $settings['telegram_webhook_secret'] ?? null);
        $this->assertTrue((bool) ($settings['telegram_webhook_secret_set'] ?? false));
        $this->assertStringNotContainsString('super-secret-value-xyz', json_encode($settings));
    }

    public function test_settings_rejects_disallowed_proxy_schemes_on_save(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Setting::setValue('telegram_webhook_secret', 'sec');

        $this->putJson('/api/settings', [
            'telegram_proxies' => [
                ['id' => 'bad', 'type' => 'url', 'url' => 'file:///etc/passwd', 'enabled' => true],
                ['id' => 'ok', 'type' => 'url', 'url' => 'socks5://127.0.0.1:1080', 'enabled' => true],
            ],
        ])->assertOk();

        $stored = (string) Setting::getValue('telegram_proxies', '[]');
        $this->assertStringNotContainsString('file://', $stored);
        $this->assertStringContainsString('socks5://127.0.0.1:1080', $stored);
    }
}
