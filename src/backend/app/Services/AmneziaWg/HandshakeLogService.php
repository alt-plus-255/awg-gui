<?php

namespace App\Services\AmneziaWg;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\AwgHandshakeLog;

class HandshakeLogService
{
    public const BYTE_LIMIT = 10 * 1024 * 1024;

    /** Soft target after trim — leave headroom before the hard cap. */
    public const BYTE_TRIM_TARGET = 9 * 1024 * 1024;

    /**
     * Fixed overhead estimate for a log row (indexes + non-string columns).
     */
    private const ROW_OVERHEAD = 96;

    public function estimateByteSize(?string $publicKey, ?string $endpoint): int
    {
        return self::ROW_OVERHEAD
            + strlen((string) $publicKey)
            + strlen((string) $endpoint);
    }

    public function record(
        AwgConfig $config,
        AwgConfigPeer $membership,
        int $handshakeAt,
        ?string $endpoint,
    ): ?AwgHandshakeLog {
        if (! $config->handshake_logging_enabled) {
            return null;
        }

        if ($handshakeAt <= 0) {
            return null;
        }

        $byteSize = $this->estimateByteSize($membership->public_key, $endpoint);

        $log = AwgHandshakeLog::query()->create([
            'awg_config_id' => $config->id,
            'awg_config_peer_id' => $membership->id,
            'vpn_client_id' => $membership->vpn_client_id,
            'public_key' => (string) $membership->public_key,
            'endpoint' => $endpoint,
            'handshake_at' => $handshakeAt,
            'byte_size' => $byteSize,
            'created_at' => now(),
        ]);

        // Refresh: multiple memberships share config id but not the same Eloquent instance.
        $config->refresh();
        $config->handshake_log_bytes = (int) $config->handshake_log_bytes + $byteSize;
        $config->save();

        $this->trimToLimit($config);

        return $log;
    }

    public function trimToLimit(AwgConfig $config): void
    {
        $config->refresh();
        $bytes = (int) $config->handshake_log_bytes;
        if ($bytes <= self::BYTE_LIMIT) {
            return;
        }

        $target = self::BYTE_TRIM_TARGET;
        $freed = 0;

        while ($bytes - $freed > $target) {
            $batch = AwgHandshakeLog::query()
                ->where('awg_config_id', $config->id)
                ->orderBy('id')
                ->limit(100)
                ->get(['id', 'byte_size']);

            if ($batch->isEmpty()) {
                break;
            }

            $ids = [];
            foreach ($batch as $row) {
                $ids[] = $row->id;
                $freed += (int) $row->byte_size;
                if ($bytes - $freed <= $target) {
                    break;
                }
            }

            AwgHandshakeLog::query()->whereIn('id', $ids)->delete();
        }

        $remaining = (int) AwgHandshakeLog::query()
            ->where('awg_config_id', $config->id)
            ->sum('byte_size');

        $config->handshake_log_bytes = $remaining;
        $config->save();
    }

    public function clear(AwgConfig $config): void
    {
        AwgHandshakeLog::query()->where('awg_config_id', $config->id)->delete();
        $config->handshake_log_bytes = 0;
        $config->save();
    }

    /**
     * @return array{
     *   logs: list<array<string, mixed>>,
     *   log_bytes: int,
     *   log_bytes_limit: int,
     *   logging_enabled: bool,
     *   has_more: bool
     * }
     */
    public function list(AwgConfig $config, ?int $vpnClientId = null, ?int $beforeId = null, int $perPage = 50): array
    {
        $perPage = max(1, min(200, $perPage));

        $query = AwgHandshakeLog::query()
            ->with('client:id,name')
            ->where('awg_config_id', $config->id)
            ->when($vpnClientId, fn ($q) => $q->where('vpn_client_id', $vpnClientId))
            ->when($beforeId, fn ($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage + 1);

        $rows = $query->get();
        $hasMore = $rows->count() > $perPage;
        if ($hasMore) {
            $rows = $rows->take($perPage);
        }

        $logs = $rows->map(fn (AwgHandshakeLog $log) => [
            'id' => $log->id,
            'vpn_client_id' => $log->vpn_client_id,
            'peer_name' => $log->client?->name,
            'public_key' => $log->public_key,
            'endpoint' => $log->endpoint,
            'handshake_at' => $log->handshake_at,
            'handshake_at_human' => $log->handshake_at ? date('c', $log->handshake_at) : null,
            'created_at' => optional($log->created_at)?->toIso8601String(),
        ])->values()->all();

        return [
            'logs' => $logs,
            'log_bytes' => (int) $config->handshake_log_bytes,
            'log_bytes_limit' => self::BYTE_LIMIT,
            'logging_enabled' => (bool) $config->handshake_logging_enabled,
            'has_more' => $hasMore,
        ];
    }
}
