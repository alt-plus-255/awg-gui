<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramSettings;
use App\Services\Telegram\TelegramUpdateRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TelegramBotCommand extends Command
{
    protected $signature = 'telegram:bot';

    protected $description = 'Long-polling Telegram bot worker (polling mode)';

    public function handle(
        TelegramSettings $settings,
        TelegramBotClient $bot,
        TelegramUpdateRouter $router,
    ): int {
        $this->info('Telegram bot worker started');
        $webhookCleared = false;

        while (true) {
            try {
                if (! $settings->isConfigured() || $settings->mode() !== TelegramSettings::MODE_POLLING) {
                    $webhookCleared = false;
                    sleep(15);

                    continue;
                }

                if (! $bot->isReady()) {
                    sleep(15);

                    continue;
                }

                if (! $webhookCleared) {
                    $deleted = $bot->deleteWebhook(false);
                    if ($deleted['ok'] ?? false) {
                        $webhookCleared = true;
                    } else {
                        Log::warning('telegram.deleteWebhook_failed', [
                            'error' => $deleted['error'] ?? $deleted['description'] ?? 'unknown',
                        ]);
                        // Still try getUpdates — may work if webhook was already absent.
                        $webhookCleared = true;
                    }
                }

                $offset = (int) Cache::get('telegram.updates.offset', 0);
                $pollStarted = microtime(true);
                $response = $bot->getUpdates($offset, 25);

                if (! ($response['ok'] ?? false)) {
                    Log::warning('telegram.getUpdates_failed', [
                        'error' => $response['error'] ?? $response['description'] ?? 'unknown',
                    ]);
                    sleep(2);

                    continue;
                }

                $updates = $response['result'] ?? [];
                if (! is_array($updates)) {
                    sleep(2);

                    continue;
                }

                foreach ($updates as $update) {
                    if (! is_array($update)) {
                        continue;
                    }
                    $updateId = (int) ($update['update_id'] ?? 0);
                    if ($updateId >= $offset) {
                        $offset = $updateId + 1;
                        Cache::forever('telegram.updates.offset', $offset);
                    }

                    try {
                        $router->handle($update);
                    } catch (\Throwable $e) {
                        Log::error('telegram.update_failed', [
                            'update_id' => $updateId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Long-poll should block ~25s; if it returns instantly, avoid a tight CPU loop.
                if ($updates === [] && (microtime(true) - $pollStarted) < 3) {
                    sleep(2);
                }
            } catch (\Throwable $e) {
                Log::error('telegram.bot_loop_error', ['error' => $e->getMessage()]);
                sleep(2);
            }
        }
    }
}
