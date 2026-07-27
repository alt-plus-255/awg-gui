<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Cache;

class TelegramConversationStore
{
    private const TTL_SEC = 900;

    /**
     * @return array{step: string, data: array<string, mixed>}|null
     */
    public function get(int|string $chatId): ?array
    {
        $value = Cache::get($this->key($chatId));
        if (! is_array($value) || empty($value['step'])) {
            return null;
        }

        return [
            'step' => (string) $value['step'],
            'data' => is_array($value['data'] ?? null) ? $value['data'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(int|string $chatId, string $step, array $data = []): void
    {
        Cache::put($this->key($chatId), [
            'step' => $step,
            'data' => $data,
        ], self::TTL_SEC);
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public function patch(int|string $chatId, ?string $step = null, array $patch = []): void
    {
        $current = $this->get($chatId) ?? ['step' => '', 'data' => []];
        $this->put(
            $chatId,
            $step ?? $current['step'],
            array_merge($current['data'], $patch),
        );
    }

    public function clear(int|string $chatId): void
    {
        Cache::forget($this->key($chatId));
    }

    private function key(int|string $chatId): string
    {
        return 'telegram.conv.'.$chatId;
    }
}
