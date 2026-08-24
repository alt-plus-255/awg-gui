<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ResolverConnection;
use App\Services\Resolver\SpeedTestService;
use Illuminate\Http\Request;

class SpeedTestController extends Controller
{
    public function __construct(private SpeedTestService $speedTest) {}

    public function status()
    {
        return response()->json($this->speedTest->status());
    }

    public function runConnection(ResolverConnection $connection, Request $request)
    {
        $data = $request->validate([
            'node_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $nodeKey = isset($data['node_key']) && is_string($data['node_key']) && $data['node_key'] !== ''
            ? $data['node_key']
            : null;

        try {
            $payload = $this->speedTest->enqueueConnection($connection, $nodeKey);

            return response()->json($payload, 202);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'async' => false,
                'error' => $e->getMessage(),
                'job' => $this->speedTest->getJob(),
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
            $payload = $this->speedTest->enqueueBatch($connections);

            return response()->json($payload, 202);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'async' => false,
                'error' => $e->getMessage(),
                'job' => $this->speedTest->getJob(),
                'results' => [],
            ], 422);
        }
    }
}
