<?php

namespace Tests\Unit;

use App\Services\Resolver\ResolverService;
use App\Services\Telegram\TelegramSettings;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class TelegramSingBoxInboundTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_inbound_added_when_connection_proxies_enabled(): void
    {
        Setting::setValue('telegram_proxies', json_encode([
            ['id' => 'c1', 'type' => 'connection', 'connection_id' => 3, 'enabled' => true],
        ]));

        $service = app(ResolverService::class);
        $method = new ReflectionMethod(ResolverService::class, 'withTelegramMixedInbound');
        $method->setAccessible(true);

        $inbounds = [];
        $outbounds = [];
        $routeRules = [
            ['protocol' => 'dns', 'action' => 'hijack-dns'],
            ['outbound' => 'direct'],
        ];
        $tags = ['conn_3' => true];

        $result = $method->invokeArgs($service, [$inbounds, &$outbounds, &$routeRules, $tags]);

        $this->assertNotEmpty($result);
        $this->assertSame(TelegramSettings::MIXED_INBOUND_TAG, $result[0]['tag']);
        $this->assertSame(TelegramSettings::MIXED_INBOUND_PORT, $result[0]['listen_port']);
        $this->assertArrayHasKey('users', $result[0]);
        $this->assertNotEmpty($result[0]['users']);
        $this->assertNotSame('', $result[0]['users'][0]['username'] ?? '');
        $this->assertNotSame('', $result[0]['users'][0]['password'] ?? '');
        $this->assertSame(TelegramSettings::MIXED_INBOUND_TAG, $routeRules[1]['inbound'][0]);
        $this->assertSame('conn_3', $routeRules[1]['outbound']);
    }

    public function test_no_inbound_without_connection_proxies(): void
    {
        Setting::setValue('telegram_proxies', '[]');
        $service = app(ResolverService::class);
        $method = new ReflectionMethod(ResolverService::class, 'withTelegramMixedInbound');
        $method->setAccessible(true);

        $inbounds = [['tag' => 'dns-in']];
        $outbounds = [];
        $routeRules = [];
        $tags = ['conn_3' => true];

        $result = $method->invokeArgs($service, [$inbounds, &$outbounds, &$routeRules, $tags]);
        $this->assertCount(1, $result);
        $this->assertSame('dns-in', $result[0]['tag']);
    }
}
