<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class TelegramBotClient
{
    public function __construct(
        private TelegramSettings $settings,
        private TelegramProxyPool $proxyPool,
    ) {}

    public function isReady(): bool
    {
        return $this->settings->token() !== '';
    }

    /**
     * @return array{ok: bool, result?: array<string, mixed>, description?: string, error?: string}
     */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{ok: bool, result?: array<string, mixed>, description?: string, error?: string}
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{ok: bool, result?: array<string, mixed>, description?: string, error?: string}
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): array
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $extra));
    }

    /**
     * @return array{ok: bool, result?: array<string, mixed>, description?: string, error?: string}
     */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null, bool $showAlert = false): array
    {
        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null && $text !== '') {
            $payload['text'] = $text;
        }
        if ($showAlert) {
            $payload['show_alert'] = true;
        }

        return $this->call('answerCallbackQuery', $payload);
    }

    /**
     * @return array{ok: bool, result?: list<array<string, mixed>>, description?: string, error?: string}
     */
    public function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], timeoutSec: $timeout + 10, usePool: true);
    }

    /**
     * @return array{ok: bool, result?: array<string, mixed>, description?: string, error?: string}
     */
    public function setWebhook(string $url, ?string $secretToken = null): array
    {
        $payload = [
            'url' => $url,
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => false,
        ];
        if ($secretToken) {
            $payload['secret_token'] = $secretToken;
        }

        return $this->call('setWebhook', $payload);
    }

    /**
     * @return array{ok: bool, result?: array<string, mixed>, description?: string, error?: string}
     */
    public function deleteWebhook(bool $dropPending = false): array
    {
        return $this->call('deleteWebhook', [
            'drop_pending_updates' => $dropPending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{ok: bool, result?: mixed, description?: string, error?: string}
     */
    public function call(string $method, array $params = [], int $timeoutSec = 30, bool $usePool = true): array
    {
        $token = $this->settings->token();
        if ($token === '') {
            return ['ok' => false, 'error' => 'telegram_token_missing'];
        }

        $url = 'https://api.telegram.org/bot'.$token.'/'.$method;
        $proxy = $usePool ? $this->proxyPool->resolveProxyUrl() : null;

        try {
            $response = $this->request($proxy, $timeoutSec)->post($url, $params);
            $parsed = $this->parseResponse($response);
            if (! ($parsed['ok'] ?? false) && $proxy !== null) {
                $this->proxyPool->markFailed($proxy);
                $fallback = $this->proxyPool->resolveProxyUrl(exclude: [$proxy]);
                if ($fallback !== null && $fallback !== $proxy) {
                    $response = $this->request($fallback, $timeoutSec)->post($url, $params);
                    $parsed = $this->parseResponse($response);
                    if ($parsed['ok'] ?? false) {
                        $this->proxyPool->markSuccess($fallback, 0);
                    }
                }
            } elseif (($parsed['ok'] ?? false) && $proxy !== null) {
                $this->proxyPool->markSuccess($proxy, 0);
            }

            return $parsed;
        } catch (\Throwable $e) {
            if ($proxy !== null) {
                $this->proxyPool->markFailed($proxy);
            }
            Log::warning('telegram.api_error', [
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Probe getMe through a specific proxy URL. Returns latency ms or null on failure.
     */
    public function probeLatency(?string $proxyUrl, int $timeoutSec = 8, ?string $tokenOverride = null): ?int
    {
        $detail = $this->probeLatencyDetailed($proxyUrl, $timeoutSec, $tokenOverride);

        return $detail['ok'] ? $detail['latency_ms'] : null;
    }

    /**
     * @return array{ok: bool, latency_ms?: int, error?: string, description?: string}
     */
    public function probeLatencyDetailed(?string $proxyUrl, int $timeoutSec = 8, ?string $tokenOverride = null): array
    {
        $token = trim((string) ($tokenOverride ?? ''));
        if ($token === '' || str_contains($token, '*')) {
            $token = $this->settings->token();
        }
        if ($token === '') {
            return ['ok' => false, 'error' => 'token_missing'];
        }

        $url = 'https://api.telegram.org/bot'.$token.'/getMe';
        $started = hrtime(true);
        try {
            $response = $this->request($proxyUrl, $timeoutSec)->post($url, []);
            $parsed = $this->parseResponse($response);
            if (! ($parsed['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => 'telegram_rejected',
                    'description' => (string) ($parsed['description'] ?? $parsed['error'] ?? 'telegram_error'),
                ];
            }

            return [
                'ok' => true,
                'latency_ms' => (int) max(1, (hrtime(true) - $started) / 1_000_000),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'proxy_unreachable',
                'description' => $e->getMessage(),
            ];
        }
    }

    private function request(?string $proxyUrl, int $timeoutSec): PendingRequest
    {
        $req = Http::asJson()
            ->acceptJson()
            ->timeout($timeoutSec)
            ->connectTimeout(min(10, $timeoutSec));

        if ($proxyUrl !== null && $proxyUrl !== '') {
            $req = $req->withOptions(['proxy' => $proxyUrl]);
        }

        return $req;
    }

    /**
     * @return array{ok: bool, result?: mixed, description?: string, error?: string}
     */
    private function parseResponse(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            return [
                'ok' => false,
                'error' => 'invalid_json',
                'description' => 'HTTP '.$response->status(),
            ];
        }

        if (! ($json['ok'] ?? false)) {
            return [
                'ok' => false,
                'description' => (string) ($json['description'] ?? 'telegram_error'),
                'error' => (string) ($json['description'] ?? 'telegram_error'),
                'result' => $json['result'] ?? null,
            ];
        }

        return [
            'ok' => true,
            'result' => $json['result'] ?? null,
        ];
    }

    public function assertOk(array $response, string $context = 'telegram'): void
    {
        if ($response['ok'] ?? false) {
            return;
        }

        throw new RuntimeException($context.': '.($response['error'] ?? $response['description'] ?? 'unknown'));
    }
}
