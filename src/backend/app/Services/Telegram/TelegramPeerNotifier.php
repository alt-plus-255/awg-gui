<?php

namespace App\Services\Telegram;

use App\Models\AwgConfigPeer;
use App\Services\AmneziaWg\PeerStatsSyncService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;

class TelegramPeerNotifier
{
    public const CACHE_KEY = 'telegram.peer.online';

    public function __construct(
        private TelegramSettings $settings,
        private TelegramBotClient $bot,
        private PeerStatsSyncService $statsSync,
    ) {}

    public function checkAndNotify(): void
    {
        if (! $this->settings->isConfigured() || ! $this->settings->notificationsEnabled()) {
            return;
        }

        App::setLocale($this->settings->language());

        if (! $this->bot->isReady()) {
            return;
        }

        $previous = Cache::get(self::CACHE_KEY, []);
        if (! is_array($previous)) {
            $previous = [];
        }

        $this->statsSync->refreshFromDocker();

        $memberships = AwgConfigPeer::query()
            ->with(['client', 'config'])
            ->whereNotNull('public_key')
            ->where('public_key', '!=', '')
            ->get();

        $current = [];
        $adminChatId = $this->settings->adminId();
        $hasBaseline = $previous !== [];

        foreach ($memberships as $membership) {
            $key = (string) $membership->public_key;
            $online = (bool) $membership->online;
            $current[$key] = $online;

            if (! $hasBaseline) {
                continue;
            }

            $was = (bool) ($previous[$key] ?? false);
            if ($was === $online) {
                continue;
            }

            $configName = $membership->config?->name ?? '#'.$membership->awg_config_id;
            $clientName = $membership->client?->name ?? '#'.$membership->vpn_client_id;

            $text = $online
                ? __('telegram.notify_online', [
                    'client' => $this->esc($clientName),
                    'config' => $this->esc($configName),
                ])
                : __('telegram.notify_offline', [
                    'client' => $this->esc($clientName),
                    'config' => $this->esc($configName),
                ]);

            $this->bot->sendMessage($adminChatId, $text);
        }

        Cache::forever(self::CACHE_KEY, $current);
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
