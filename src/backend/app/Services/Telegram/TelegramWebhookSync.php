<?php

namespace App\Services\Telegram;

use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Resolver\ResolverService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramWebhookSync
{
    public function __construct(
        private TelegramSettings $settings,
        private TelegramBotClient $bot,
        private AmneziaWgService $awg,
        private TelegramProxyPool $proxyPool,
    ) {}

    /**
     * Apply transport mode after settings change (set/delete webhook, refresh proxy cache, maybe rebuild sing-box).
     *
     * @return array{ok: bool, message?: string, bot?: array<string, mixed>}
     */
    public function syncAfterSettingsChange(bool $proxiesChanged = false): array
    {
        $this->proxyPool->clearCache();

        $needsResolverApply = $proxiesChanged;
        if ($this->settings->hasEnabledConnectionProxies()) {
            // Keep sing-box mixed inbound users aligned with mixedProxyUrl() credentials.
            $this->settings->mixedAuth();
            $needsResolverApply = true;
        }

        if ($needsResolverApply) {
            try {
                app(ResolverService::class)->apply(refreshSubscriptions: false);
            } catch (\Throwable $e) {
                Log::warning('telegram.resolver_apply_after_proxy_change', ['error' => $e->getMessage()]);
            }
        }

        if (! $this->settings->isConfigured()) {
            return ['ok' => true, 'message' => 'not_configured'];
        }

        if ($this->settings->mode() === TelegramSettings::MODE_WEBHOOK) {
            $secret = $this->settings->webhookSecret();
            $url = rtrim($this->awg->resolvePanelUrl(), '/').'/api/telegram/webhook/'.$secret;
            $result = $this->bot->setWebhook($url, $secret);
            if (! ($result['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'message' => $result['error'] ?? $result['description'] ?? 'setWebhook failed',
                ];
            }

            return ['ok' => true, 'message' => 'webhook_set'];
        }

        $result = $this->bot->deleteWebhook(false);
        if (! ($result['ok'] ?? false)) {
            return [
                'ok' => false,
                'message' => $result['error'] ?? $result['description'] ?? 'deleteWebhook failed',
            ];
        }

        return ['ok' => true, 'message' => 'webhook_deleted'];
    }

    /**
     * @return array{ok: bool, bot?: array<string, mixed>, error?: string, message?: string, proxies?: list<array<string, mixed>>}
     */
    public function testBot(bool $probeProxies = false): array
    {
        if ($this->settings->token() === '') {
            return [
                'ok' => false,
                'error' => 'token_missing',
                'message' => __('settings.telegram_token_missing'),
            ];
        }

        $me = $this->bot->getMe();
        if (! ($me['ok'] ?? false)) {
            $raw = (string) ($me['error'] ?? $me['description'] ?? 'getMe failed');

            return [
                'ok' => false,
                'error' => 'bot_unreachable',
                'message' => __('settings.telegram_bot_unreachable', ['detail' => $raw]),
            ];
        }

        $out = [
            'ok' => true,
            'bot' => is_array($me['result'] ?? null) ? $me['result'] : [],
            'message' => __('settings.telegram_bot_ok'),
        ];

        if ($probeProxies && $this->settings->mode() === TelegramSettings::MODE_POLLING) {
            $proxies = $this->proxyPool->probeStatus();
            $out['proxies'] = $proxies;
            if ($proxies === []) {
                $out['message'] = __('settings.telegram_proxies_empty');
            } else {
                $okCount = count(array_filter($proxies, fn ($row) => ! empty($row['ok'])));
                $out['message'] = __('settings.telegram_proxies_probed', [
                    'ok' => $okCount,
                    'total' => count($proxies),
                ]);
            }
        }

        return $out;
    }

    /**
     * Validate and probe a proxy URL before adding it to the pool.
     *
     * @return array{ok: bool, latency_ms?: int, error?: string, message?: string, url_masked?: string}
     */
    public function testProxyUrl(string $url, ?string $tokenOverride = null): array
    {
        $url = trim($url);
        if ($url === '') {
            return [
                'ok' => false,
                'error' => 'url_empty',
                'message' => __('settings.telegram_proxy_url_empty'),
            ];
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return [
                'ok' => false,
                'error' => 'invalid_url',
                'message' => __('settings.telegram_proxy_invalid_url'),
            ];
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['socks5', 'socks5h', 'http', 'https'], true)) {
            return [
                'ok' => false,
                'error' => 'unsupported_scheme',
                'message' => __('settings.telegram_proxy_unsupported_scheme'),
            ];
        }

        $token = trim((string) ($tokenOverride ?? ''));
        if ($token === '' || str_contains($token, '*')) {
            $token = $this->settings->token();
        }
        if ($token === '') {
            return [
                'ok' => false,
                'error' => 'token_missing',
                'message' => __('settings.telegram_token_missing_for_proxy'),
            ];
        }

        $detail = $this->bot->probeLatencyDetailed($url, 12, $token);
        if (! ($detail['ok'] ?? false)) {
            $error = (string) ($detail['error'] ?? 'proxy_unreachable');
            $message = match ($error) {
                'token_missing' => __('settings.telegram_token_missing_for_proxy'),
                'telegram_rejected' => __('settings.telegram_proxy_telegram_rejected', [
                    'detail' => (string) ($detail['description'] ?? ''),
                ]),
                default => __('settings.telegram_proxy_unreachable'),
            };

            return [
                'ok' => false,
                'error' => $error,
                'message' => $message,
            ];
        }

        return [
            'ok' => true,
            'latency_ms' => (int) ($detail['latency_ms'] ?? 0),
            'url_masked' => $this->settings->maskProxyUrl($url),
            'message' => __('settings.telegram_proxy_ok', [
                'ms' => (int) ($detail['latency_ms'] ?? 0),
            ]),
        ];
    }

    public function ensureWebhookSecret(): string
    {
        return $this->settings->webhookSecret();
    }

    public function newProxyId(): string
    {
        return Str::lower(Str::random(8));
    }
}
