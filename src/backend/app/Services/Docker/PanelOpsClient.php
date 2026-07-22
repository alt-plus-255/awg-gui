<?php

namespace App\Services\Docker;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PanelOpsClient
{
    public function recreateCaddy(): void
    {
        $baseUrl = rtrim((string) ($this->envValue('PANEL_OPS_URL') ?: 'http://panel-ops:8090'), '/');
        $token = trim((string) ($this->envValue('PANEL_OPS_TOKEN') ?: ''));

        if ($token === '') {
            throw new RuntimeException('PANEL_OPS_TOKEN is not configured');
        }

        $response = Http::timeout(180)
            ->withToken($token)
            ->acceptJson()
            ->post("{$baseUrl}/ops/caddy/recreate");

        if (! $response->successful()) {
            $error = trim((string) ($response->json('error') ?? $response->body()));
            throw new RuntimeException($error !== '' ? $error : 'panel-ops recreate failed');
        }

        if ($response->json('ok') !== true) {
            $error = trim((string) ($response->json('error') ?? 'panel-ops recreate failed'));
            throw new RuntimeException($error !== '' ? $error : 'panel-ops recreate failed');
        }
    }

    private function envValue(string $key): ?string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        $fromEnv = env($key);

        return $fromEnv !== null && $fromEnv !== '' ? (string) $fromEnv : null;
    }
}
