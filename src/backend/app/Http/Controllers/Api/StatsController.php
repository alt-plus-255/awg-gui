<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AwgConfigPeer;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\PeerStatsSyncService;
use App\Services\System\HostMetricsService;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function __construct(
        private AmneziaWgService $awg,
        private PeerStatsSyncService $statsSync,
        private HostMetricsService $hostMetrics,
    ) {}

    public function index(Request $request)
    {
        $configId = $this->parseSingleConfigId($request);
        $includeLinks = $request->boolean('include_links', true);

        $result = $this->statsSync->peersFromDb($configId);
        $online = collect($result['peers'])->where('online', true)->count();

        return response()->json([
            'stats_available' => true,
            'summary' => [
                'clients_total' => VpnClient::query()->count(),
                'memberships_total' => AwgConfigPeer::query()
                    ->when($configId, fn ($q) => $q->where('awg_config_id', $configId))
                    ->count(),
                'memberships_enabled' => AwgConfigPeer::query()
                    ->when($configId, fn ($q) => $q->where('awg_config_id', $configId))
                    ->where('enabled', true)
                    ->count(),
                'online' => $online,
            ],
            'peers' => $result['peers'],
            'links' => $includeLinks ? $this->awg->peerLinks($configId) : [],
            'host' => $this->hostMetrics->collect(),
        ]);
    }

    public function refresh(Request $request)
    {
        $configIds = $this->parseConfigIds($request);
        $result = $this->statsSync->refreshFromDocker($configIds);
        $online = collect($result['peers'])->where('online', true)->count();

        return response()->json([
            'stats_available' => $result['stats_available'],
            'synced_at' => $result['synced_at'],
            'by_public_key' => $result['by_public_key'],
            'summary' => [
                'clients_total' => VpnClient::query()->count(),
                'memberships_total' => AwgConfigPeer::query()
                    ->when($configIds !== null, fn ($q) => $q->whereIn('awg_config_id', $configIds))
                    ->count(),
                'memberships_enabled' => AwgConfigPeer::query()
                    ->when($configIds !== null, fn ($q) => $q->whereIn('awg_config_id', $configIds))
                    ->where('enabled', true)
                    ->count(),
                'online' => $online,
            ],
            'peers' => $result['peers'],
            'host' => $this->hostMetrics->collect(),
        ]);
    }

    /** @deprecated Use POST /api/stats/refresh */
    public function live(Request $request)
    {
        $configIds = $this->parseConfigIds($request);
        $result = $this->statsSync->refreshFromDocker($configIds);

        return response()->json([
            'stats_available' => $result['stats_available'],
            'by_public_key' => $result['by_public_key'],
        ]);
    }

    private function parseSingleConfigId(Request $request): ?int
    {
        $configId = $request->query('config_id');
        if ($configId === null || $configId === '') {
            return null;
        }

        return (int) $configId;
    }

    /**
     * @return list<int>|null null = all configs
     */
    private function parseConfigIds(Request $request): ?array
    {
        $raw = $request->query('config_ids');
        if ($raw !== null && $raw !== '') {
            if (is_string($raw)) {
                $parts = preg_split('/\s*,\s*/', $raw) ?: [];
            } else {
                $parts = (array) $raw;
            }

            $ids = array_values(array_unique(array_filter(
                array_map('intval', $parts),
                fn (int $id) => $id > 0
            )));

            return $ids;
        }

        $single = $this->parseSingleConfigId($request);
        if ($single !== null) {
            return [$single];
        }

        return null;
    }
}
