<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\Setting;
use App\Services\AmneziaWg\PeerStatsSyncService;
use App\Services\System\HostMetricsService;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramUpdateRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class TelegramUpdateRouterTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_admin_start_sends_dashboard_summary(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');
        Setting::setValue('telegram_language', 'ru');

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function ($chatId, $text, $extra = []) {
                return (int) $chatId === 42
                    && str_contains((string) $text, 'Главное меню')
                    && str_contains((string) $text, 'CPU:')
                    && str_contains((string) $text, 'AWG:')
                    && isset($extra['reply_markup']);
            })
            ->andReturn(['ok' => true, 'result' => ['message_id' => 1]]);
        $this->app->instance(TelegramBotClient::class, $bot);

        $stats = Mockery::mock(PeerStatsSyncService::class);
        $stats->shouldReceive('peersFromDb')->andReturn(['stats_available' => true, 'peers' => []]);
        $this->app->instance(PeerStatsSyncService::class, $stats);

        $host = Mockery::mock(HostMetricsService::class);
        $host->shouldReceive('collect')->andReturn([
            'cpu' => ['percent' => 12.5],
            'memory' => ['percent' => 40.0],
            'disk' => ['percent' => 55.0],
            'uptime_seconds' => 86400,
            'loadavg' => [1 => 0.1, 5 => 0.2, 15 => 0.3],
        ]);
        $this->app->instance(HostMetricsService::class, $host);

        app(TelegramUpdateRouter::class)->handle([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'text' => '/start',
                'chat' => ['id' => 42],
                'from' => ['id' => 42],
            ],
        ]);
    }

    public function test_non_admin_start_gets_forbidden(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (int) $chatId === 999 && $text === __('telegram.forbidden'))
            ->andReturn(['ok' => true]);
        $this->app->instance(TelegramBotClient::class, $bot);

        app(TelegramUpdateRouter::class)->handle([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'text' => '/start',
                'chat' => ['id' => 999],
                'from' => ['id' => 999],
            ],
        ]);
    }

    public function test_resolver_enable_shows_confirm_before_toggle(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');
        Setting::setValue('telegram_language', 'en');

        $config = AwgConfig::query()->create([
            'name' => 'Srv',
            'type' => 'server',
            'iface' => 'awg9',
            'listen_port' => 51829,
            'internal_subnet' => '10.99.99.0/24',
            'server_address' => '10.99.99.1/24',
            'server_private_key' => 'priv',
            'server_public_key' => 'pub',
            'enabled' => true,
            'resolver_enabled' => true,
            'community_lists' => [],
            'user_domains' => [],
            'user_subnets' => [],
        ]);

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('answerCallbackQuery')->once()->andReturn(['ok' => true]);
        $bot->shouldReceive('editMessageText')
            ->once()
            ->withArgs(function ($chatId, $messageId, $text, $extra = []) {
                return (int) $chatId === 42
                    && (int) $messageId === 7
                    && str_contains((string) $text, 'Disable resolver')
                    && isset($extra['reply_markup']['inline_keyboard']);
            })
            ->andReturn(['ok' => true]);
        $this->app->instance(TelegramBotClient::class, $bot);

        app(TelegramUpdateRouter::class)->handle([
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => 42],
                'data' => 'res:en:'.$config->id,
                'message' => [
                    'message_id' => 7,
                    'chat' => ['id' => 42],
                ],
            ],
        ]);

        $this->assertTrue((bool) $config->fresh()->resolver_enabled);
    }

    public function test_notifications_confirm_then_toggle(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');
        Setting::setValue('telegram_language', 'en');
        Setting::setValue('telegram_notifications_enabled', '1');

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('answerCallbackQuery')->twice()->andReturn(['ok' => true]);
        $bot->shouldReceive('editMessageText')
            ->once()
            ->withArgs(fn ($chatId, $messageId, $text) => str_contains((string) $text, 'Disable peer online/offline'))
            ->andReturn(['ok' => true]);
        $bot->shouldReceive('editMessageText')
            ->once()
            ->withArgs(fn ($chatId, $messageId, $text) => str_contains((string) $text, 'Notifications'))
            ->andReturn(['ok' => true]);
        $this->app->instance(TelegramBotClient::class, $bot);

        $router = app(TelegramUpdateRouter::class);
        $router->handle([
            'update_id' => 3,
            'callback_query' => [
                'id' => 'cb2',
                'from' => ['id' => 42],
                'data' => 'notif:en',
                'message' => ['message_id' => 8, 'chat' => ['id' => 42]],
            ],
        ]);
        $this->assertSame('1', (string) Setting::getValue('telegram_notifications_enabled'));

        $router->handle([
            'update_id' => 4,
            'callback_query' => [
                'id' => 'cb3',
                'from' => ['id' => 42],
                'data' => 'notif:enok',
                'message' => ['message_id' => 8, 'chat' => ['id' => 42]],
            ],
        ]);
        $this->assertSame('0', (string) Setting::getValue('telegram_notifications_enabled'));
    }

    public function test_daily_report_confirm_then_toggle(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');
        Setting::setValue('telegram_language', 'en');
        Setting::setValue('telegram_daily_report_enabled', '1');

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('answerCallbackQuery')->twice()->andReturn(['ok' => true]);
        $bot->shouldReceive('editMessageText')
            ->once()
            ->withArgs(fn ($chatId, $messageId, $text) => str_contains((string) $text, 'Disable daily report'))
            ->andReturn(['ok' => true]);
        $bot->shouldReceive('editMessageText')
            ->once()
            ->withArgs(fn ($chatId, $messageId, $text) => str_contains((string) $text, 'Daily report'))
            ->andReturn(['ok' => true]);
        $this->app->instance(TelegramBotClient::class, $bot);

        $router = app(TelegramUpdateRouter::class);
        $router->handle([
            'update_id' => 5,
            'callback_query' => [
                'id' => 'cb4',
                'from' => ['id' => 42],
                'data' => 'notif:daily:en',
                'message' => ['message_id' => 9, 'chat' => ['id' => 42]],
            ],
        ]);
        $this->assertSame('1', (string) Setting::getValue('telegram_daily_report_enabled'));

        $router->handle([
            'update_id' => 6,
            'callback_query' => [
                'id' => 'cb5',
                'from' => ['id' => 42],
                'data' => 'notif:daily:enok',
                'message' => ['message_id' => 9, 'chat' => ['id' => 42]],
            ],
        ]);
        $this->assertSame('0', (string) Setting::getValue('telegram_daily_report_enabled'));
    }
}
