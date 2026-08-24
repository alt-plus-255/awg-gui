<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramPeerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TelegramPeerNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_skips_when_not_configured(): void
    {
        Cache::flush();
        $notifier = app(TelegramPeerNotifier::class);
        $notifier->checkAndNotify();
        $this->assertFalse(Cache::has(TelegramPeerNotifier::CACHE_KEY));
    }
}
