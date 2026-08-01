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

    /**
     * @return array<string, mixed>
     */
    public function awgKernelStatus(): array
    {
        $baseUrl = rtrim((string) ($this->envValue('PANEL_OPS_URL') ?: 'http://panel-ops:8090'), '/');
        $token = trim((string) ($this->envValue('PANEL_OPS_TOKEN') ?: ''));

        if ($token === '') {
            throw new RuntimeException('PANEL_OPS_TOKEN is not configured');
        }

        $response = Http::timeout(120)
            ->withToken($token)
            ->acceptJson()
            ->get("{$baseUrl}/ops/awg-kernel/status");

        if (! $response->successful()) {
            $error = trim((string) ($response->json('error') ?? $response->body()));
            throw new RuntimeException($error !== '' ? $error : 'panel-ops awg-kernel status failed');
        }

        $body = $response->json();

        return is_array($body) ? $body : ['ok' => false];
    }

    /**
     * @param  'install'|'uninstall'  $op
     * @return array<string, mixed>
     */
    public function startAwgKernelOp(string $op): array
    {
        if (! in_array($op, ['install', 'uninstall'], true)) {
            throw new RuntimeException('Invalid awg-kernel op');
        }

        $baseUrl = rtrim((string) ($this->envValue('PANEL_OPS_URL') ?: 'http://panel-ops:8090'), '/');
        $token = trim((string) ($this->envValue('PANEL_OPS_TOKEN') ?: ''));

        if ($token === '') {
            throw new RuntimeException('PANEL_OPS_TOKEN is not configured');
        }

        $response = Http::timeout(15)
            ->withToken($token)
            ->acceptJson()
            ->post("{$baseUrl}/ops/awg-kernel/{$op}", []);

        if ($response->status() === 409) {
            throw new RuntimeException('kernel_op_already_running');
        }

        if (! $response->successful()) {
            $error = trim((string) ($response->json('error') ?? $response->body()));
            throw new RuntimeException($error !== '' ? $error : 'panel-ops awg-kernel op failed');
        }

        $body = $response->json();

        return is_array($body) ? $body : ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function startUpdate(?string $version = null): array
    {
        $baseUrl = rtrim((string) ($this->envValue('PANEL_OPS_URL') ?: 'http://panel-ops:8090'), '/');
        $token = trim((string) ($this->envValue('PANEL_OPS_TOKEN') ?: ''));

        if ($token === '') {
            throw new RuntimeException('PANEL_OPS_TOKEN is not configured');
        }

        $payload = [];
        if ($version !== null && $version !== '') {
            $payload['version'] = $version;
        }

        $response = Http::timeout(15)
            ->withToken($token)
            ->acceptJson()
            ->post("{$baseUrl}/ops/update/start", $payload);

        if (! $response->successful()) {
            $error = trim((string) ($response->json('error') ?? $response->body()));
            throw new RuntimeException($error !== '' ? $error : 'panel-ops update start failed');
        }

        $body = $response->json();

        return is_array($body) ? $body : ['ok' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function clearUpdateLog(): array
    {
        $baseUrl = rtrim((string) ($this->envValue('PANEL_OPS_URL') ?: 'http://panel-ops:8090'), '/');
        $token = trim((string) ($this->envValue('PANEL_OPS_TOKEN') ?: ''));

        if ($token === '') {
            throw new RuntimeException('PANEL_OPS_TOKEN is not configured');
        }

        $response = Http::timeout(15)
            ->withToken($token)
            ->acceptJson()
            ->post("{$baseUrl}/ops/update/clear-log");

        if (! $response->successful() || $response->json('ok') !== true) {
            $error = trim((string) ($response->json('error') ?? $response->body()));
            throw new RuntimeException($error !== '' ? $error : 'panel-ops clear update log failed');
        }

        $body = $response->json();

        return is_array($body) ? $body : ['ok' => true];
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
