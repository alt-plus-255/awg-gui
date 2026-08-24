<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\System\HostMetricsService;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramDailyReportNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class TelegramDailyReportNotifierTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_skips_when_not_configured(): void
    {
        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('isReady')->never();
        $bot->shouldReceive('sendMessage')->never();
        $this->app->instance(TelegramBotClient::class, $bot);

        $sent = app(TelegramDailyReportNotifier::class)->send();
        $this->assertFalse($sent);
    }

    public function test_skips_when_daily_report_disabled(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');
        Setting::setValue('telegram_daily_report_enabled', '0');

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('isReady')->never();
        $bot->shouldReceive('sendMessage')->never();
        $this->app->instance(TelegramBotClient::class, $bot);

        $this->assertFalse(app(TelegramDailyReportNotifier::class)->send());
    }

    public function test_build_report_includes_key_sections(): void
    {
        Setting::setValue('telegram_language', 'en');

        $host = Mockery::mock(HostMetricsService::class);
        $host->shouldReceive('collect')->andReturn([
            'cpu' => ['percent' => 12.5],
            'memory' => ['used' => 1024 * 1024 * 100, 'total' => 1024 * 1024 * 1024, 'percent' => 10.0],
            'disk' => ['percent' => 40.0],
            'uptime_seconds' => 90061,
            'loadavg' => [1 => 0.17, 5 => 0.41, 15 => 0.26],
        ]);
        $this->app->instance(HostMetricsService::class, $host);

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('isContainerRunning')->andReturn(true);
        $awg->shouldReceive('endpointStatus')->andReturn(['endpoint' => '1.2.3.4']);
        $this->app->instance(AmneziaWgService::class, $awg);

        $text = app(TelegramDailyReportNotifier::class)->buildReport();

        $this->assertStringContainsString('@daily', $text);
        $this->assertStringContainsString('Hostname', $text);
        $this->assertStringContainsString('1 d 01:01', $text);
        $this->assertStringContainsString('0.17, 0.41, 0.26', $text);
        $this->assertStringContainsString('AWG: up', $text);
        $this->assertStringContainsString('Resolver enabled configs', $text);
    }

    public function test_send_posts_message_when_enabled(): void
    {
        Setting::setValue('telegram_bot_token', '1:token');
        Setting::setValue('telegram_admin_id', '42');
        Setting::setValue('telegram_language', 'en');
        Setting::setValue('telegram_daily_report_enabled', '1');

        $host = Mockery::mock(HostMetricsService::class);
        $host->shouldReceive('collect')->andReturn([
            'cpu' => ['percent' => null],
            'memory' => ['used' => null, 'total' => null, 'percent' => null],
            'disk' => ['percent' => null],
            'uptime_seconds' => null,
            'loadavg' => [1 => null, 5 => null, 15 => null],
        ]);
        $this->app->instance(HostMetricsService::class, $host);

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('isContainerRunning')->andReturn(false);
        $awg->shouldReceive('endpointStatus')->andReturn(['endpoint' => '']);
        $this->app->instance(AmneziaWgService::class, $awg);

        $bot = Mockery::mock(TelegramBotClient::class);
        $bot->shouldReceive('isReady')->once()->andReturn(true);
        $bot->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($chatId, $text) => (int) $chatId === 42 && str_contains((string) $text, '@daily'))
            ->andReturn(['ok' => true]);
        $this->app->instance(TelegramBotClient::class, $bot);

        $this->assertTrue(app(TelegramDailyReportNotifier::class)->send());
    }
}
