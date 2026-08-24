<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class TelegramProxyPoolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshDatabase;

    public function test_resolve_proxy_url_stops_at_first_ok(): void
    {
        Cache::flush();
        Setting::setValue('telegram_mode', 'polling');
        Setting::setValue('telegram_proxies', json_encode([
            ['id' => 'a', 'type' => 'url', 'url' => 'socks5://1.1.1.1:1080', 'enabled' => true],
            ['id' => 'b', 'type' => 'url', 'url' => 'socks5://2.2.2.2:1080', 'enabled' => true],
            ['id' => 'c', 'type' => 'url', 'url' => 'socks5://3.3.3.3:1080', 'enabled' => true],
        ]));

        $calls = 0;
        $mock = Mockery::mock(TelegramBotClient::class);
        $mock->shouldReceive('probeLatency')
            ->andReturnUsing(function () use (&$calls) {
                $calls++;

                return 40;
            });
        $this->app->instance(TelegramBotClient::class, $mock);

        $url = app(TelegramProxyPool::class)->resolveProxyUrl();

        $this->assertSame('socks5://1.1.1.1:1080', $url);
        $this->assertSame(1, $calls);
    }

    public function test_resolve_proxy_url_uses_cache_without_probe(): void
    {
        Cache::flush();
        Setting::setValue('telegram_mode', 'polling');
        Setting::setValue('telegram_proxies', json_encode([
            ['id' => 'a', 'type' => 'url', 'url' => 'socks5://1.1.1.1:1080', 'enabled' => true],
            ['id' => 'b', 'type' => 'url', 'url' => 'socks5://2.2.2.2:1080', 'enabled' => true],
        ]));

        Cache::put('telegram.proxy.winner', 'socks5://2.2.2.2:1080', 120);

        $mock = Mockery::mock(TelegramBotClient::class);
        $mock->shouldNotReceive('probeLatency');
        $this->app->instance(TelegramBotClient::class, $mock);

        $url = app(TelegramProxyPool::class)->resolveProxyUrl();

        $this->assertSame('socks5://2.2.2.2:1080', $url);
    }
}
