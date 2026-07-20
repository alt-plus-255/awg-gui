<?php

namespace App\Services\AmneziaWg;

use App\Services\System\HostMetricsService;
use App\WebSocket\WsConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SplObjectStorage;

class StatsBroadcaster
{
    private const INTERVAL_SEC = 8;

    /** @var SplObjectStorage<WsConnection, array{config_ids: array<int, true>}> */
    private SplObjectStorage $connections;

    /** @var array<int, int> */
    private array $configRefCount = [];

    public function __construct(
        private AmneziaWgService $awg,
        private HostMetricsService $hostMetrics,
    ) {
        $this->connections = new SplObjectStorage;
    }

    public function authenticate(WsConnection $conn, string $token): bool
    {
        $userId = Cache::get('ws_token:'.$token);
        if (! $userId) {
            return false;
        }

        $this->connections->attach($conn, ['config_ids' => []]);

        return true;
    }

    public function detach(WsConnection $conn): void
    {
        if (! $this->connections->contains($conn)) {
            return;
        }

        $meta = $this->connections[$conn];
        $this->unsubscribe($conn, array_keys($meta['config_ids']));
        $this->connections->detach($conn);
    }

    /** @param list<int> $configIds */
    public function subscribe(WsConnection $conn, array $configIds): void
    {
        if (! $this->connections->contains($conn)) {
            return;
        }

        $meta = $this->connections[$conn];
        $newIds = [];

        foreach ($configIds as $configId) {
            $configId = (int) $configId;
            if ($configId <= 0) {
                continue;
            }
            if (isset($meta['config_ids'][$configId])) {
                continue;
            }
            $meta['config_ids'][$configId] = true;
            $newIds[] = $configId;
            $this->configRefCount[$configId] = ($this->configRefCount[$configId] ?? 0) + 1;
        }

        $this->connections[$conn] = $meta;

        foreach ($newIds as $configId) {
            $this->pushConfigStats($conn, $configId);
        }

        if ($newIds !== []) {
            $this->pushHost($conn);
        }
    }

    /** @param list<int> $configIds */
    public function unsubscribe(WsConnection $conn, array $configIds): void
    {
        if (! $this->connections->contains($conn)) {
            return;
        }

        $meta = $this->connections[$conn];

        foreach ($configIds as $configId) {
            $configId = (int) $configId;
            if ($configId <= 0 || ! isset($meta['config_ids'][$configId])) {
                continue;
            }
            unset($meta['config_ids'][$configId]);
            $this->decrementConfigRef($configId);
        }

        $this->connections[$conn] = $meta;
    }

    public function tick(): void
    {
        if ($this->connections->count() === 0) {
            return;
        }

        $this->broadcastHost();

        $configIds = array_keys(array_filter(
            $this->configRefCount,
            fn (int $count) => $count > 0
        ));

        foreach ($configIds as $configId) {
            $this->broadcastConfigStats((int) $configId);
        }
    }

    public function intervalSeconds(): int
    {
        return self::INTERVAL_SEC;
    }

    private function decrementConfigRef(int $configId): void
    {
        if (! isset($this->configRefCount[$configId])) {
            return;
        }

        $this->configRefCount[$configId]--;
        if ($this->configRefCount[$configId] <= 0) {
            unset($this->configRefCount[$configId]);
        }
    }

    private function pushConfigStats(WsConnection $conn, int $configId): void
    {
        try {
            $payload = $this->buildStatsPayload($configId);
            $conn->send(json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            Log::warning('ws stats push failed', [
                'config_id' => $configId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function pushHost(WsConnection $conn): void
    {
        try {
            $conn->send(json_encode($this->buildHostPayload(), JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            Log::warning('ws host push failed', ['error' => $e->getMessage()]);
        }
    }

    private function broadcastHost(): void
    {
        try {
            $message = json_encode($this->buildHostPayload(), JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('ws host broadcast failed', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($this->connections as $conn) {
            try {
                $conn->send($message);
            } catch (\Throwable $e) {
                Log::warning('ws send failed', ['error' => $e->getMessage()]);
            }
        }
    }

    private function broadcastConfigStats(int $configId): void
    {
        try {
            $payload = $this->buildStatsPayload($configId);
            $message = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            Log::warning('ws stats broadcast failed', [
                'config_id' => $configId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($this->connections as $conn) {
            $meta = $this->connections[$conn];
            if (! isset($meta['config_ids'][$configId])) {
                continue;
            }

            try {
                $conn->send($message);
            } catch (\Throwable $e) {
                Log::warning('ws send failed', ['error' => $e->getMessage()]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function buildStatsPayload(int $configId): array
    {
        $result = $this->awg->livePeerStats($configId);

        return [
            'type' => 'stats',
            'config_id' => $configId,
            'stats_available' => $result['stats_available'],
            'by_public_key' => $result['by_public_key'],
            'synced_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function buildHostPayload(): array
    {
        return [
            'type' => 'host',
            'host' => $this->hostMetrics->collect(),
            'synced_at' => now()->toIso8601String(),
        ];
    }
}
