<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use Illuminate\Support\Str;

class TelegramSettings
{
    public const MODE_POLLING = 'polling';

    public const MODE_WEBHOOK = 'webhook';

    public const LANG_EN = 'en';

    public const LANG_RU = 'ru';

    public const STRATEGY_FASTEST = 'fastest';

    public const STRATEGY_FIRST_OK = 'first_ok';

    public const MIXED_INBOUND_PORT = 18088;

    public const MIXED_INBOUND_TAG = 'tg-in';

    public const TELEGRAM_OUTBOUND_TAG = 'telegram-out';

    /**
     * @return array{
     *   token: string,
     *   admin_id: string,
     *   language: string,
     *   mode: string,
     *   proxies: list<array<string, mixed>>,
     *   proxy_strategy: string,
     *   notifications_enabled: bool,
     *   webhook_secret: string
     * }
     */
    public function all(): array
    {
        return [
            'token' => $this->token(),
            'admin_id' => $this->adminId(),
            'language' => $this->language(),
            'mode' => $this->mode(),
            'proxies' => $this->proxies(),
            'proxy_strategy' => $this->proxyStrategy(),
            'notifications_enabled' => $this->notificationsEnabled(),
            'webhook_secret' => $this->webhookSecret(),
        ];
    }

    public function token(): string
    {
        return trim((string) Setting::getValue('telegram_bot_token', ''));
    }

    public function adminId(): string
    {
        return trim((string) Setting::getValue('telegram_admin_id', ''));
    }

    public function mode(): string
    {
        $mode = trim((string) Setting::getValue('telegram_mode', self::MODE_POLLING));

        return in_array($mode, [self::MODE_POLLING, self::MODE_WEBHOOK], true)
            ? $mode
            : self::MODE_POLLING;
    }

    public function language(): string
    {
        $lang = trim((string) Setting::getValue('telegram_language', self::LANG_EN));

        return in_array($lang, [self::LANG_EN, self::LANG_RU], true)
            ? $lang
            : self::LANG_EN;
    }

    public function proxyStrategy(): string
    {
        $strategy = trim((string) Setting::getValue('telegram_proxy_strategy', self::STRATEGY_FASTEST));

        return in_array($strategy, [self::STRATEGY_FASTEST, self::STRATEGY_FIRST_OK], true)
            ? $strategy
            : self::STRATEGY_FASTEST;
    }

    public function notificationsEnabled(): bool
    {
        return filter_var(Setting::getValue('telegram_notifications_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    public function webhookSecret(): string
    {
        $secret = trim((string) Setting::getValue('telegram_webhook_secret', ''));
        if ($secret !== '') {
            return $secret;
        }

        $secret = Str::random(32);
        Setting::setValue('telegram_webhook_secret', $secret);

        return $secret;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function proxies(): array
    {
        $raw = Setting::getValue('telegram_proxies', '[]');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            $enabled = filter_var($row['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '') {
                $id = Str::lower(Str::random(8));
            }

            if ($type === 'url') {
                $url = trim((string) ($row['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'type' => 'url',
                    'url' => $url,
                    'enabled' => $enabled,
                ];
                continue;
            }

            if ($type === 'connection') {
                $connectionId = (int) ($row['connection_id'] ?? 0);
                if ($connectionId < 1) {
                    continue;
                }
                $out[] = [
                    'id' => $id,
                    'type' => 'connection',
                    'connection_id' => $connectionId,
                    'enabled' => $enabled,
                ];
            }
        }

        return $out;
    }

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->adminId() !== '';
    }

    public function isAdmin(int|string|null $telegramUserId): bool
    {
        if ($telegramUserId === null || $telegramUserId === '') {
            return false;
        }

        $admin = $this->adminId();
        if ($admin === '') {
            return false;
        }

        return (string) $telegramUserId === $admin;
    }

    /**
     * @return list<int>
     */
    public function enabledConnectionIds(): array
    {
        $ids = [];
        foreach ($this->proxies() as $proxy) {
            if (($proxy['type'] ?? '') !== 'connection' || ! ($proxy['enabled'] ?? false)) {
                continue;
            }
            $ids[] = (int) $proxy['connection_id'];
        }

        return array_values(array_unique($ids));
    }

    public function hasEnabledConnectionProxies(): bool
    {
        return $this->enabledConnectionIds() !== [];
    }

    /**
     * Credentials for the internal sing-box mixed inbound used by Telegram proxy pool.
     *
     * @return array{username: string, password: string}
     */
    public function mixedAuth(): array
    {
        $this->ensureMixedAuth();

        return [
            'username' => trim((string) Setting::getValue('telegram_mixed_auth_user', '')),
            'password' => trim((string) Setting::getValue('telegram_mixed_auth_pass', '')),
        ];
    }

    /**
     * Ensure mixed-inbound credentials exist. Returns true when they were just created
     * (caller should rebuild sing-box so the inbound users match).
     */
    public function ensureMixedAuth(): bool
    {
        $user = trim((string) Setting::getValue('telegram_mixed_auth_user', ''));
        $pass = trim((string) Setting::getValue('telegram_mixed_auth_pass', ''));
        if ($user !== '' && $pass !== '') {
            return false;
        }

        Setting::setValue('telegram_mixed_auth_user', 'tg_'.Str::lower(Str::random(10)));
        Setting::setValue('telegram_mixed_auth_pass', Str::random(32));

        return true;
    }

    public function mixedProxyUrl(): string
    {
        $host = trim((string) env('AWG_PROXY_HOST', 'awg'));
        if ($host === '') {
            $host = 'awg';
        }

        $auth = $this->mixedAuth();

        return 'socks5h://'.rawurlencode($auth['username']).':'.rawurlencode($auth['password'])
            .'@'.$host.':'.self::MIXED_INBOUND_PORT;
    }

    /**
     * Mask token for API responses.
     */
    public function maskToken(?string $token = null): string
    {
        $token = $token ?? $this->token();
        if ($token === '') {
            return '';
        }
        if (strlen($token) <= 10) {
            return '********';
        }

        return substr($token, 0, 4).str_repeat('*', max(4, strlen($token) - 8)).substr($token, -4);
    }

    /**
     * @param  list<array<string, mixed>>  $proxies
     */
    public function encodeProxies(array $proxies): string
    {
        return json_encode(array_values($proxies), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }

    /**
     * Settings payload for the panel API (token masked).
     *
     * @return array<string, mixed>
     */
    public function forApi(): array
    {
        $proxies = $this->proxies();
        foreach ($proxies as &$proxy) {
            if (($proxy['type'] ?? '') === 'url' && isset($proxy['url'])) {
                $proxy['url_masked'] = $this->maskProxyUrl((string) $proxy['url']);
                $proxy['url'] = $proxy['url_masked'];
            }
        }
        unset($proxy);

        $webhookSet = trim((string) Setting::getValue('telegram_webhook_secret', '')) !== '';

        return [
            'telegram_bot_token' => $this->maskToken(),
            'telegram_bot_token_set' => $this->token() !== '',
            'telegram_admin_id' => $this->adminId(),
            'telegram_language' => $this->language(),
            'telegram_mode' => $this->mode(),
            'telegram_proxies' => $proxies,
            'telegram_proxy_strategy' => $this->proxyStrategy(),
            'telegram_notifications_enabled' => $this->notificationsEnabled() ? '1' : '0',
            // Never expose the real webhook secret to the browser.
            'telegram_webhook_secret' => $webhookSet ? '********' : '',
            'telegram_webhook_secret_set' => $webhookSet,
        ];
    }

    public function maskProxyUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return '***';
        }
        $scheme = $parts['scheme'] ?? 'socks5';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $user = isset($parts['user']) ? '***@' : '';

        return $scheme.'://'.$user.$host.$port;
    }
}
