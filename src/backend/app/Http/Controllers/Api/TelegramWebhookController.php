<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramSettings;
use App\Services\Telegram\TelegramUpdateRouter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        TelegramSettings $settings,
        TelegramUpdateRouter $router,
    ) {
        $expected = $settings->webhookSecret();
        if ($expected === '' || ! hash_equals($expected, $secret)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Telegram always sends this when setWebhook(secret_token) was used.
        $headerToken = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if ($headerToken === '' || ! hash_equals($expected, $headerToken)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($settings->mode() !== TelegramSettings::MODE_WEBHOOK) {
            return response()->json(['ok' => true, 'ignored' => 'mode']);
        }

        if (! $settings->isConfigured()) {
            return response()->json(['ok' => true, 'ignored' => 'not_configured']);
        }

        $update = $request->all();
        if (! is_array($update) || $update === []) {
            return response()->json(['ok' => false], 400);
        }

        try {
            $router->handle($update);
        } catch (\Throwable $e) {
            Log::error('telegram.webhook_failed', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
