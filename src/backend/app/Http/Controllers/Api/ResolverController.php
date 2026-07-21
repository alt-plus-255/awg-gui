<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AwgConfig;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Resolver\ResolverService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ResolverController extends Controller
{
    public function __construct(
        private ResolverService $resolver,
        private AmneziaWgService $awg,
    ) {}

    public function show()
    {
        return response()->json($this->resolver->status());
    }

    public function updateConfig(Request $request, AwgConfig $config)
    {
        $data = $request->validate([
            'resolver_enabled' => ['required', 'boolean'],
            'resolver_routing_mode' => ['sometimes', 'string', 'in:vds_split,client_split'],
            'resolver_reject_quic' => ['sometimes', 'boolean'],
            'connection_id' => ['nullable', 'integer', 'exists:resolver_connections,id'],
            'resolver_dns' => ['sometimes', 'nullable', 'string', 'max:255'],
            'community_lists' => ['sometimes', 'array'],
            'community_lists.*' => ['string', 'max:64'],
            'user_domains' => ['sometimes', 'array'],
            'user_domains.*' => ['string', 'max:255'],
            'user_subnets' => ['sometimes', 'array'],
            'user_subnets.*' => ['string', 'max:64'],
        ]);

        if (array_key_exists('resolver_routing_mode', $data)) {
            $config->resolver_routing_mode = $data['resolver_routing_mode'];
        }
        if (array_key_exists('resolver_reject_quic', $data)) {
            $config->resolver_reject_quic = (bool) $data['resolver_reject_quic'];
        }
        if (array_key_exists('resolver_dns', $data)) {
            $dns = trim((string) ($data['resolver_dns'] ?? ''));
            if ($dns === '') {
                $dns = '1.1.1.1';
            }
            if (! filter_var($dns, FILTER_VALIDATE_IP) && ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $dns)) {
                throw ValidationException::withMessages([
                    'resolver_dns' => [__('resolver.dns_required')],
                ]);
            }
            $config->resolver_dns = $dns;
        }

        if ($data['resolver_enabled']) {
            $this->resolver->assertCanEnable($config);
        } elseif ($config->type === 'virtual_network') {
            $config->resolver_enabled = false;
            $config->connection_id = null;
            $config->resolver_last_error = null;
            $config->save();
            $this->awg->applyConfig($config, refreshSubscriptions: false);

            return response()->json([
                'ok' => true,
                'status' => $this->resolver->status(),
                'warning' => null,
            ]);
        }

        if ($data['resolver_enabled']) {
            $normalized = $this->resolver->normalizeLists(
                $data['community_lists'] ?? ($config->community_lists ?? []),
                $data['user_domains'] ?? ($config->user_domains ?? []),
                $data['user_subnets'] ?? ($config->user_subnets ?? []),
            );
            if ($normalized['community_lists'] === [] && $normalized['user_domains'] === [] && $normalized['user_subnets'] === []) {
                throw ValidationException::withMessages([
                    'community_lists' => [__('resolver.select_at_least_one_list')],
                ]);
            }

            $connectionId = array_key_exists('connection_id', $data)
                ? $data['connection_id']
                : $config->connection_id;
            $conn = $this->resolver->assertConnectionSelected($config, $connectionId ? (int) $connectionId : null);

            $config->resolver_enabled = true;
            $config->connection_id = $conn->id;
            $config->community_lists = $normalized['community_lists'];
            $config->user_domains = $normalized['user_domains'];
            $config->user_subnets = $normalized['user_subnets'];
        } else {
            $config->resolver_enabled = false;
            if (array_key_exists('connection_id', $data)) {
                $config->connection_id = $data['connection_id'];
            }
            if (array_key_exists('community_lists', $data) || array_key_exists('user_domains', $data) || array_key_exists('user_subnets', $data)) {
                $normalized = $this->resolver->normalizeLists(
                    $data['community_lists'] ?? ($config->community_lists ?? []),
                    $data['user_domains'] ?? ($config->user_domains ?? []),
                    $data['user_subnets'] ?? ($config->user_subnets ?? []),
                );
                $config->community_lists = $normalized['community_lists'];
                $config->user_domains = $normalized['user_domains'];
                $config->user_subnets = $normalized['user_subnets'];
            }
            $config->resolver_last_error = null;
        }

        $config->save();
        $this->awg->applyConfig($config, refreshSubscriptions: false);

        $needsClientReimport = $config->wasChanged('resolver_enabled')
            || $config->wasChanged('resolver_routing_mode')
            || (
                $config->resolverRoutingMode() === AwgConfig::ROUTING_MODE_CLIENT_SPLIT
                && $config->wasChanged('user_subnets')
            );

        return response()->json([
            'ok' => true,
            'status' => $this->resolver->status(),
            'warning' => $needsClientReimport
                ? __('resolver.clients_need_reimport')
                : null,
        ]);
    }

    public function refresh()
    {
        try {
            $this->resolver->apply(forceSyncLists: true);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'resolver' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'ok' => true,
            'status' => $this->resolver->status(),
        ]);
    }

    public function diagnose()
    {
        return response()->json($this->resolver->diagnose());
    }
}
