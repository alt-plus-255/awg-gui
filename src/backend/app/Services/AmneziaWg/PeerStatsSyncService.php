<?php

namespace App\Services\AmneziaWg;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use Illuminate\Support\Carbon;

class PeerStatsSyncService
{
    private const ONLINE_WINDOW_SEC = 180;

    public function __construct(private AmneziaWgService $awg) {}

    /**
     * @return array{stats_available:bool,peers:array<int, array<string, mixed>>}
     */
    public function peersFromDb(?int $configId = null): array
    {
        $memberships = AwgConfigPeer::query()
            ->with(['client', 'config'])
            ->when($configId, fn ($q) => $q->where('awg_config_id', $configId))
            ->orderBy('id')
            ->get();

        $configs = $memberships
            ->pluck('config')
            ->filter()
            ->unique('id');

        foreach ($configs as $config) {
            $this->awg->primeConfigPeerCache($config);
        }

        $peers = $memberships
            ->map(fn (AwgConfigPeer $m) => $m->config
                ? $this->serializePeer($m->config, $m)
                : null)
            ->filter()
            ->values()
            ->all();

        return [
            'stats_available' => true,
            'peers' => $peers,
        ];
    }

    /**
     * Sync runtime stats from docker. Returns lightweight peer stats only
     * (no AllowedIPs serialization) — callers merge into existing peer rows.
     *
     * @param  list<int>|int|null  $configIds  null = all enabled configs
     * @return array{
     *   stats_available: bool,
     *   synced_at: ?string,
     *   peers: array<int, array<string, mixed>>,
     *   by_public_key: array<string, array<string, mixed>>
     * }
     */
    public function refreshFromDocker(int|array|null $configIds = null): array
    {
        $ids = $this->normalizeConfigIds($configIds);
        $live = $this->awg->livePeerStats($ids);
        $now = now();

        $memberships = AwgConfigPeer::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('awg_config_id', $ids))
            ->get();

        foreach ($memberships as $membership) {
            $stat = $live['by_public_key'][$membership->public_key] ?? null;
            $this->applyStatsToMembership($membership, $stat, $now);
        }

        $byPublicKey = [];
        $peersLite = [];

        foreach ($memberships as $membership) {
            if (empty($membership->public_key)) {
                continue;
            }

            $handshake = $membership->latest_handshake;
            $entry = [
                'config_id' => $membership->awg_config_id,
                'public_key' => $membership->public_key,
                'endpoint' => $membership->runtime_endpoint,
                'latest_handshake' => $handshake ?: null,
                'latest_handshake_human' => $handshake ? date('c', $handshake) : null,
                'transfer_rx' => (int) ($membership->transfer_rx ?? 0),
                'transfer_tx' => (int) ($membership->transfer_tx ?? 0),
                'online' => (bool) $membership->online,
            ];

            $byPublicKey[$membership->public_key] = $entry;
            $peersLite[] = $entry;
        }

        return [
            'stats_available' => $live['stats_available'],
            'synced_at' => $now->toIso8601String(),
            'peers' => $peersLite,
            'by_public_key' => $byPublicKey,
        ];
    }

    public function serializePeer(AwgConfig $config, AwgConfigPeer $membership): array
    {
        $handshake = $membership->latest_handshake;
        $online = $membership->online;
        if ($online === null && $handshake) {
            $online = $handshake > 0 && (time() - $handshake) < self::ONLINE_WINDOW_SEC;
        }

        return [
            'membership_id' => $membership->id,
            'config_id' => $config->id,
            'config_name' => $config->name,
            'config_iface' => $config->iface,
            'config_type' => $config->type,
            'id' => $membership->vpn_client_id,
            'client_id' => $membership->vpn_client_id,
            'vpn_client_id' => $membership->vpn_client_id,
            'name' => $membership->client?->name,
            'comment' => $membership->client?->comment,
            'enabled' => $membership->enabled,
            'address' => $membership->address,
            'extra_allowed_ips' => array_values($membership->extra_allowed_ips ?? []),
            'excluded_client_ids' => array_values(array_map('intval', $membership->excluded_client_ids ?? [])),
            'exclusions_mutual' => (bool) $membership->exclusions_mutual,
            'server_allowed_ips' => $this->awg->serverPeerAllowedIpsString($membership),
            'client_allowed_ips' => $this->awg->clientAllowedIpsString($config, $membership),
            'public_key' => $membership->public_key,
            'use_preshared_key' => (bool) $membership->preshared_key,
            'keepalive' => $membership->keepalive,
            'endpoint' => $membership->runtime_endpoint,
            'latest_handshake' => $handshake ?: null,
            'latest_handshake_human' => $handshake ? date('c', $handshake) : null,
            'transfer_rx' => (int) ($membership->transfer_rx ?? 0),
            'transfer_tx' => (int) ($membership->transfer_tx ?? 0),
            'online' => $online,
            'stats_synced_at' => optional($membership->stats_synced_at)?->toIso8601String(),
            'created_at' => $membership->created_at,
            'updated_at' => $membership->updated_at,
        ];
    }

    /**
     * @param  list<int>|int|null  $configIds
     * @return list<int>|null
     */
    private function normalizeConfigIds(int|array|null $configIds): ?array
    {
        if ($configIds === null) {
            return null;
        }

        if (is_int($configIds)) {
            return $configIds > 0 ? [$configIds] : [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $configIds),
            fn (int $id) => $id > 0
        )));
    }

    /**
     * @param  array<string, mixed>|null  $stat
     */
    private function applyStatsToMembership(AwgConfigPeer $membership, ?array $stat, Carbon $now): void
    {
        if ($stat === null) {
            $membership->runtime_endpoint = null;
            $membership->latest_handshake = null;
            $membership->transfer_rx = 0;
            $membership->transfer_tx = 0;
            $membership->online = false;
            $membership->stats_synced_at = $now;
            $membership->save();

            return;
        }

        $handshake = (int) ($stat['latest_handshake'] ?? 0);
        $online = $stat['online'] ?? ($handshake > 0 && (time() - $handshake) < self::ONLINE_WINDOW_SEC);

        $membership->runtime_endpoint = $stat['endpoint'] ?? null;
        $membership->latest_handshake = $handshake ?: null;
        $membership->transfer_rx = (int) ($stat['transfer_rx'] ?? 0);
        $membership->transfer_tx = (int) ($stat['transfer_tx'] ?? 0);
        $membership->online = (bool) $online;
        $membership->stats_synced_at = $now;
        $membership->save();
    }
}
