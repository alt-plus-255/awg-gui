<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function __construct(private AmneziaWgService $awg) {}

    public function index()
    {
        $clients = VpnClient::query()
            ->with(['memberships.config'])
            ->orderBy('id')
            ->get()
            ->map(fn (VpnClient $c) => $this->serialize($c, includeAllowedIps: false));

        return response()->json(['clients' => $clients]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        $client = VpnClient::query()->create([
            'name' => $data['name'],
            'comment' => $data['comment'] ?? null,
        ]);

        return response()->json(['client' => $this->serialize($client)], 201);
    }

    public function update(Request $request, VpnClient $client)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('name', $data)) {
            $client->name = $data['name'];
        }
        if (array_key_exists('comment', $data)) {
            $client->comment = $data['comment'];
        }

        $client->save();
        $this->awg->applyAfterClientChange($client);

        return response()->json(['client' => $this->serialize($client->load('memberships.config'))]);
    }

    public function destroy(VpnClient $client)
    {
        $configIds = AwgConfigPeer::query()
            ->where('vpn_client_id', $client->id)
            ->pluck('awg_config_id')
            ->unique()
            ->all();

        $client->delete();

        foreach ($configIds as $configId) {
            $config = AwgConfig::query()->find($configId);
            if ($config) {
                $this->awg->applyConfig($config, withResolver: false);
            }
        }

        return response()->json(['ok' => true]);
    }

    private function serialize(VpnClient $client, bool $includeAllowedIps = true): array
    {
        $memberships = $client->relationLoaded('memberships')
            ? $client->memberships
            : $client->memberships()->with('config')->get();

        return [
            'id' => $client->id,
            'name' => $client->name,
            'comment' => $client->comment,
            'memberships' => $memberships->map(function (AwgConfigPeer $m) use ($includeAllowedIps) {
                $config = $m->config;
                $row = [
                    'membership_id' => $m->id,
                    'config_id' => $m->awg_config_id,
                    'config_name' => $config?->name,
                    'config_type' => $config?->type,
                    'enabled' => $m->enabled,
                    'address' => $m->address,
                    'extra_allowed_ips' => array_values($m->extra_allowed_ips ?? []),
                ];
                if ($includeAllowedIps) {
                    $row['client_allowed_ips'] = $config
                        ? $this->awg->clientAllowedIpsString($config, $m)
                        : null;
                }

                return $row;
            })->values(),
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
        ];
    }
}
