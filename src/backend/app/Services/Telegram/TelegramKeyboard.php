<?php

namespace App\Services\Telegram;

class TelegramKeyboard
{
    /**
     * @param  list<list<array{text: string, callback_data: string}>>  $rows
     * @return array{inline_keyboard: list<list<array{text: string, callback_data: string}>>}
     */
    public static function inline(array $rows): array
    {
        return ['inline_keyboard' => $rows];
    }

    /**
     * @return array{text: string, callback_data: string}
     */
    public static function btn(string $text, string $callbackData): array
    {
        return ['text' => $text, 'callback_data' => mb_substr($callbackData, 0, 64)];
    }

    /**
     * @param  list<array{text: string, callback_data: string}>  $buttons
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public static function chunk(array $buttons, int $perRow = 2): array
    {
        return array_chunk($buttons, max(1, $perRow));
    }
}
