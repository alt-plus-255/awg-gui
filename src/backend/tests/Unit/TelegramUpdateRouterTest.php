<?php

namespace Tests\Unit;

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
}
