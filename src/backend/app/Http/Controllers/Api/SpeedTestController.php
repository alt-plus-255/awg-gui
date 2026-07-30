<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResolverConnection;
use App\Services\Resolver\SpeedTestService;
use Illuminate\Http\Request;

class SpeedTestController extends Controller
{
    public function __construct(private SpeedTestService $speedTest) {}

    public function runConnection(ResolverConnection $connection, Request $request)
    {
        $data = $request->validate([
            'node_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $nodeKey = isset($data['node_key']) && is_string($data['node_key']) && $data['node_key'] !== ''
            ? $data['node_key']
            : null;

        try {
            $result = $this->speedTest->run($connection, $nodeKey);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'outbound_tag' => $connection->outboundTag(),
                'connection_id' => (int) $connection->id,
                'node_key' => $nodeKey,
                'ping_ms' => null,
                'download_mbps' => null,
                'upload_mbps' => null,
                'download_bytes' => null,
                'upload_bytes' => null,
                'download_ms' => null,
                'upload_ms' => null,
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function runBatch()
    {
        $connections = ResolverConnection::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        try {
            $results = $this->speedTest->runBatch($connections);

            return response()->json([
                'ok' => true,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'results' => [],
            ], 422);
        }
    }
}
