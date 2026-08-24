<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

class TelegramProxyPool
{
    private const CACHE_KEY = 'telegram.proxy.winner';

    private const FAIL_PREFIX = 'telegram.proxy.fail.';

    private const PROBE_TTL_SEC = 120;

    private const FAIL_TTL_SEC = 90;

    /** Short probe for getUpdates hot path — avoid N×8s blocking the poll loop. */
    private const HOT_PROBE_TIMEOUT_SEC = 3;

    public function __construct(
        private TelegramSettings $settings,
    ) {}

    /**
     * Pick a proxy for API calls. Hot path: cached winner, else first live with short probe.
     * Full multi-proxy latency ranking stays in probeStatus() for the UI.
     *
     * @param  list<string>  $exclude
     */
    public function resolveProxyUrl(array $exclude = []): ?string
    {
        if ($this->settings->mode() !== TelegramSettings::MODE_POLLING) {
            return null;
        }

        $candidates = $this->candidateUrls();
        if ($candidates === []) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '' && ! in_array($cached, $exclude, true) && ! $this->isFailed($cached)) {
            if (in_array($cached, $candidates, true)) {
                return $cached;
            }
        }

        $winner = $this->probeFirstOk($exclude);
        if ($winner !== null) {
            Cache::put(self::CACHE_KEY, $winner, self::PROBE_TTL_SEC);

            return $winner;
        }

        // Last resort: first non-excluded candidate even if probe failed.
        foreach ($candidates as $url) {
            if (! in_array($url, $exclude, true)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @return list<array{url: string, latency_ms: int|null, ok: bool, source: string}>
     */
    public function probeStatus(): array
    {
        $rows = [];
        foreach ($this->candidateMeta() as $meta) {
            $latency = app(TelegramBotClient::class)->probeLatency($meta['url']);
            $rows[] = [
                'url' => $meta['display'],
                'latency_ms' => $latency,
                'ok' => $latency !== null,
                'source' => $meta['source'],
                'id' => $meta['id'],
            ];
        }

        return $rows;
    }

    public function markFailed(string $proxyUrl): void
    {
        Cache::put(self::FAIL_PREFIX.sha1($proxyUrl), 1, self::FAIL_TTL_SEC);
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached === $proxyUrl) {
            Cache::forget(self::CACHE_KEY);
        }
    }

    public function markSuccess(string $proxyUrl, int $latencyMs = 0): void
    {
        Cache::forget(self::FAIL_PREFIX.sha1($proxyUrl));
        Cache::put(self::CACHE_KEY, $proxyUrl, self::PROBE_TTL_SEC);
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return list<string>
     */
    public function candidateUrls(): array
    {
        return array_values(array_unique(array_column($this->candidateMeta(), 'url')));
    }

    /**
     * @return list<array{id: string, url: string, display: string, source: string}>
     */
    private function candidateMeta(): array
    {
        $meta = [];
        $hasConnection = false;

        foreach ($this->settings->proxies() as $proxy) {
            if (! ($proxy['enabled'] ?? false)) {
                continue;
            }
            if (($proxy['type'] ?? '') === 'url') {
                $url = trim((string) ($proxy['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                $meta[] = [
                    'id' => (string) $proxy['id'],
                    'url' => $url,
                    'display' => $this->settings->maskProxyUrl($url),
                    'source' => 'url',
                ];
            }
            if (($proxy['type'] ?? '') === 'connection') {
                $hasConnection = true;
            }
        }

        if ($hasConnection) {
            $mixed = $this->settings->mixedProxyUrl();
            $meta[] = [
                'id' => 'resolver-mixed',
                'url' => $mixed,
                'display' => $this->settings->maskProxyUrl($mixed),
                'source' => 'connection',
            ];
        }

        return $meta;
    }

    /**
     * @param  list<string>  $exclude
     */
    private function probeFirstOk(array $exclude): ?string
    {
        $client = app(TelegramBotClient::class);
        foreach ($this->candidateMeta() as $meta) {
            $url = $meta['url'];
            if (in_array($url, $exclude, true) || $this->isFailed($url)) {
                continue;
            }
            $latency = $client->probeLatency($url, self::HOT_PROBE_TIMEOUT_SEC);
            if ($latency === null) {
                $this->markFailed($url);

                continue;
            }

            return $url;
        }

        return null;
    }

    private function isFailed(string $proxyUrl): bool
    {
        return Cache::has(self::FAIL_PREFIX.sha1($proxyUrl));
    }
}
