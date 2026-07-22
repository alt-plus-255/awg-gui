<?php

namespace App\Services\Diagnostics;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\ResolverConnection;
use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\PingProbeManager;
use App\Services\Resolver\ResolverPaths;
use App\Services\Resolver\ResolverService;

class DiagnosticsService
{
    private const CONTAINERS = [
        ['name' => 'awggui-awg', 'label' => 'AmneziaWG'],
        ['name' => 'awggui-app', 'label' => 'panel_api'],
        ['name' => 'awggui-db', 'label' => 'MariaDB'],
        ['name' => 'awggui-caddy', 'label' => 'Caddy'],
        ['name' => 'awggui-docker-proxy', 'label' => 'docker_proxy'],
        ['name' => 'awggui-panel-ops', 'label' => 'panel_ops'],
        ['name' => 'awggui-certbot', 'label' => 'certbot'],
    ];

    private const MASK_JSON_KEYS = [
        'password',
        'private_key',
        'uuid',
        'auth',
        'token',
        'secret',
        'psk',
        'api_key',
    ];

    public function __construct(
        private AmneziaWgService $awg,
        private DockerRuntime $docker,
        private ResolverService $resolver,
    ) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $containers = $this->containerChecks();
        $singbox = $this->singBoxRunningCheck();
        $ifaces = $this->ifaceStatusList();
        $configs = AwgConfig::query()
            ->orderBy('id')
            ->get(['id', 'name', 'type', 'iface', 'enabled', 'resolver_enabled'])
            ->map(fn (AwgConfig $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'type_label' => $c->type === 'virtual_network' ? __('api.type_virtual_network') : __('api.type_server'),
                'iface' => $c->iface,
                'enabled' => (bool) $c->enabled,
                'resolver_enabled' => (bool) $c->resolver_enabled,
            ])
            ->values()
            ->all();

        $containersOk = ! in_array(false, array_column($containers, 'ok'), true);
        $ifacesOk = $ifaces === [] || ! in_array(false, array_column($ifaces, 'ok'), true);

        return [
            'ok' => $containersOk && $singbox['ok'] && $ifacesOk,
            'containers' => $containers,
            'singbox' => $singbox,
            'ping_probe' => $this->pingProbeStatus(),
            'ifaces' => $ifaces,
            'configs' => $configs,
            'system' => [
                'endpoint' => trim((string) Setting::getValue('server_endpoint', env('SERVER_ENDPOINT', 'auto'))),
                'panel_port' => (int) env('PANEL_PORT', 8877),
                'timezone' => (string) Setting::getValue('timezone', env('TZ', 'UTC')),
                'awg_container' => $this->awg->containerName(),
            ],
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<int>|null  $configIds
     * @return array<string, mixed>
     */
    public function run(?array $configIds = null): array
    {
        $configs = AwgConfig::query()
            ->when($configIds !== null && $configIds !== [], fn ($q) => $q->whereIn('id', $configIds))
            ->orderBy('id')
            ->get();

        $hints = [];
        $groups = [];

        $runtime = $this->groupRuntime();
        $groups[] = $runtime;
        $hints = [...$hints, ...($runtime['hints'] ?? [])];

        $awgGroup = $this->groupAwgIfaces($configs);
        $groups[] = $awgGroup;
        $hints = [...$hints, ...($awgGroup['hints'] ?? [])];

        $resolverGroup = $this->groupResolver($configs);
        $groups[] = $resolverGroup;
        $hints = [...$hints, ...($resolverGroup['hints'] ?? [])];

        $outboundsGroup = $this->groupOutbounds($configs);
        $groups[] = $outboundsGroup;
        $hints = [...$hints, ...($outboundsGroup['hints'] ?? [])];

        $vnGroup = $this->groupVirtualNetworks($configs);
        $groups[] = $vnGroup;
        $hints = [...$hints, ...($vnGroup['hints'] ?? [])];

        $allOk = ! in_array(false, array_column($groups, 'ok'), true);
        $anyFail = in_array(false, array_column($groups, 'ok'), true);
        $anyPass = in_array(true, array_column($groups, 'ok'), true);

        return [
            'ok' => $allOk,
            'status' => $allOk ? 'success' : ($anyPass && $anyFail ? 'warning' : 'error'),
            'groups' => array_map(function (array $g) {
                unset($g['hints']);

                return $g;
            }, $groups),
            'hints' => array_values(array_unique(array_filter($hints))),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function singBoxConfig(): array
    {
        $path = $this->resolver->singBoxConfigPath();
        if (! is_file($path)) {
            return [
                'ok' => false,
                'masked' => true,
                'content' => null,
                'error' => __('system.singbox_json_not_found'),
                'updated_at' => null,
            ];
        }

        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'masked' => true,
                'content' => $this->maskAwgConfText($raw),
                'error' => __('system.invalid_json_masked_raw'),
                'updated_at' => date('c', (int) filemtime($path)),
            ];
        }

        $masked = $this->maskJsonSecrets($decoded);
        $content = json_encode($masked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'ok' => true,
            'masked' => true,
            'content' => $content === false ? null : $content."\n",
            'error' => null,
            'updated_at' => date('c', (int) filemtime($path)),
        ];
    }

    /** @return array<string, mixed> */
    public function awgConfigs(): array
    {
        $dir = $this->awg->configDir();
        $dbConfigs = AwgConfig::query()->orderBy('id')->get()->keyBy('iface');
        $items = [];

        foreach (glob($dir.'/awg*.conf') ?: [] as $path) {
            $iface = basename($path, '.conf');
            $cfg = $dbConfigs->get($iface);
            $raw = (string) file_get_contents($path);
            $items[] = [
                'iface' => $iface,
                'name' => $cfg?->name ?? $iface,
                'type' => $cfg?->type ?? null,
                'type_label' => $cfg
                    ? ($cfg->type === 'virtual_network' ? __('api.type_virtual_network') : __('api.type_server'))
                    : null,
                'config_id' => $cfg?->id,
                'content' => $this->maskAwgConfText($raw),
                'updated_at' => date('c', (int) filemtime($path)),
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['iface'], $b['iface']));

        return [
            'ok' => true,
            'masked' => true,
            'configs' => $items,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function containerChecks(): array
    {
        $out = [];
        foreach (self::CONTAINERS as $c) {
            $name = $c['name'] === 'awggui-awg' ? $this->awg->containerName() : $c['name'];
            $running = $this->awg->isContainerRunning($name);
            $out[] = [
                'name' => $name,
                'label' => $c['label'] === 'panel_api' ? __('system.container_panel_api') : $c['label'],
                'ok' => $running,
                'running' => $running,
                'detail' => $running ? 'running' : 'stopped',
            ];
        }

        return $out;
    }

    /** @return array{ok:bool,running:bool,label:string,detail:string} */
    private function singBoxRunningCheck(): array
    {
        $running = false;
        $detail = __('system.awg_container_not_running');
        if ($this->awg->isContainerRunning()) {
            try {
                $r = $this->docker->exec(
                    $this->awg->containerName(),
                    ['sh', '-c', 'pgrep -x sing-box >/dev/null && echo yes || echo no'],
                    timeout: 10,
                );
                $running = trim($r->output()) === 'yes';
                $detail = $running ? __('system.process_running') : __('system.process_not_found');
            } catch (\Throwable $e) {
                $detail = $e->getMessage();
            }
        }

        return [
            'ok' => $running,
            'running' => $running,
            'label' => 'sing-box',
            'detail' => $detail,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ifaceStatusList(?iterable $configs = null): array
    {
        $configs ??= AwgConfig::query()->where('enabled', true)->orderBy('id')->get();
        $out = [];
        $awgUp = $this->awg->isContainerRunning();

        foreach ($configs as $config) {
            /** @var AwgConfig $config */
            if (! $awgUp) {
                $out[] = [
                    'config_id' => $config->id,
                    'name' => $config->name,
                    'iface' => $config->iface,
                    'type' => $config->type,
                    'ok' => false,
                    'up' => false,
                    'detail' => __('system.awg_container_not_running'),
                ];

                continue;
            }

            $up = $this->ifaceIsUp($config->iface);
            $out[] = [
                'config_id' => $config->id,
                'name' => $config->name,
                'iface' => $config->iface,
                'type' => $config->type,
                'ok' => $up,
                'up' => $up,
                'detail' => $up ? 'up' : __('system.iface_down_or_missing'),
            ];
        }

        return $out;
    }

    private function ifaceIsUp(string $iface): bool
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $iface)) {
            return false;
        }

        try {
            $r = $this->docker->exec(
                $this->awg->containerName(),
                ['sh', '-c', 'ip link show '.$iface.' >/dev/null 2>&1 && echo yes || echo no'],
                timeout: 8,
            );

            return trim($r->output()) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    private function awgShowAvailable(string $iface): bool
    {
        try {
            $r = $this->docker->exec(
                $this->awg->containerName(),
                ['awg', 'show', $iface],
                timeout: 8,
            );

            return $r->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function groupRuntime(): array
    {
        $checks = [];
        $hints = [];

        foreach ($this->containerChecks() as $c) {
            $checks[] = [
                'id' => 'container_'.$c['name'],
                'ok' => $c['ok'],
                'label' => $c['label'].' ('.$c['name'].')',
                'detail' => $c['detail'],
            ];
            if (! $c['ok']) {
                $hints[] = __('system.container_not_running_hint', ['name' => $c['name']]);
            }
        }

        $singbox = $this->singBoxRunningCheck();
        $checks[] = [
            'id' => 'singbox_running',
            'ok' => $singbox['ok'],
            'label' => 'sing-box',
            'detail' => $singbox['detail'],
        ];
        if (! $singbox['ok']) {
            $hints[] = __('system.singbox_not_running_hint');
        }

        return $this->finalizeGroup('runtime', __('system.group_runtime'), $checks, $hints);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AwgConfig>  $configs
     * @return array<string, mixed>
     */
    private function groupAwgIfaces($configs): array
    {
        $checks = [];
        $hints = [];
        $targets = $configs->where('enabled', true)->values();

        if ($targets->isEmpty()) {
            $checks[] = [
                'id' => 'awg_ifaces_none',
                'ok' => true,
                'label' => __('system.awg_ifaces_label'),
                'detail' => __('system.no_enabled_configs_in_selection'),
            ];

            return $this->finalizeGroup('awg', 'AWG ifaces', $checks, $hints);
        }

        foreach ($targets as $config) {
            $up = $this->awg->isContainerRunning() && $this->ifaceIsUp($config->iface);
            $showOk = $up && $this->awgShowAvailable($config->iface);
            $typeLabel = $config->type === 'virtual_network' ? 'VN' : 'server';
            $checks[] = [
                'id' => 'iface_'.$config->iface,
                'ok' => $up && $showOk,
                'label' => $config->name.' ('.$config->iface.', '.$typeLabel.')',
                'detail' => ! $up
                    ? 'iface down'
                    : ($showOk ? 'up · awg show OK' : __('system.awg_show_unavailable')),
            ];
            if (! $up) {
                $hints[] = __('system.iface_not_up_hint', ['iface' => $config->iface, 'name' => $config->name]);
            } elseif (! $showOk) {
                $hints[] = __('system.awg_show_no_response_hint', ['iface' => $config->iface]);
            }
        }

        return $this->finalizeGroup('awg', 'AWG ifaces', $checks, $hints);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AwgConfig>  $configs
     * @return array<string, mixed>
     */
    private function groupResolver($configs): array
    {
        $serverIds = $configs->where('type', 'server')->pluck('id')->all();
        $hasResolverScope = $configs->contains(fn (AwgConfig $c) => $c->type === 'server' && $c->resolver_enabled);

        if ($serverIds === [] || ! $hasResolverScope) {
            $anyServer = $configs->contains(fn (AwgConfig $c) => $c->type === 'server');
            $checks = [[
                'id' => 'resolver_skipped',
                'ok' => true,
                'label' => __('system.resolver_label'),
                'detail' => $anyServer
                    ? __('system.no_resolver_enabled_servers')
                    : __('system.no_server_configs_in_selection'),
            ]];

            return $this->finalizeGroup('resolver', __('system.group_resolver'), $checks, []);
        }

        $diagnose = $this->resolver->diagnose();
        $checks = [];
        foreach ($diagnose['checks'] ?? [] as $c) {
            $id = (string) ($c['id'] ?? '');
            // Keep global runtime checks; filter ruleset/dns probes that mention other configs when filtered
            if (str_starts_with($id, 'merged_cfg_')) {
                $cfgId = (int) substr($id, strlen('merged_cfg_'));
                if ($serverIds !== [] && ! in_array($cfgId, $serverIds, true)) {
                    continue;
                }
            }
            $checks[] = [
                'id' => $c['id'],
                'ok' => (bool) ($c['ok'] ?? false),
                'label' => $c['label'] ?? $id,
                'detail' => $c['detail'] ?? '',
            ];
        }

        return $this->finalizeGroup(
            'resolver',
            __('system.group_resolver'),
            $checks,
            is_array($diagnose['hints'] ?? null) ? $diagnose['hints'] : []
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AwgConfig>  $configs
     * @return array<string, mixed>
     */
    private function groupOutbounds($configs): array
    {
        $checks = [];
        $hints = [];

        $connectionIds = $configs
            ->where('type', 'server')
            ->where('resolver_enabled', true)
            ->pluck('connection_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($connectionIds === []) {
            $enabled = ResolverConnection::query()->where('enabled', true)->orderBy('id')->get();
            if ($enabled->isEmpty()) {
                $checks[] = [
                    'id' => 'outbounds_none',
                    'ok' => true,
                    'label' => 'Outbounds',
                    'detail' => __('system.no_active_resolver_connections'),
                ];

                return $this->finalizeGroup('outbounds', 'Outbounds', $checks, $hints);
            }
            $connections = $enabled;
        } else {
            $connections = ResolverConnection::query()
                ->whereIn('id', $connectionIds)
                ->orderBy('id')
                ->get();
        }

        if (! $this->awg->isContainerRunning()) {
            $checks[] = [
                'id' => 'outbounds_no_awg',
                'ok' => false,
                'label' => 'Outbounds',
                'detail' => __('system.awg_container_not_running'),
            ];

            return $this->finalizeGroup('outbounds', 'Outbounds', $checks, [__('system.start_awg_for_outbounds')]);
        }

        foreach ($connections as $conn) {
            $tag = $conn->outboundTag();
            $result = $this->resolver->testOutboundDelay($tag);
            $ok = (bool) ($result['ok'] ?? false);
            $latency = $result['latency_ms'] ?? null;
            $checks[] = [
                'id' => 'outbound_'.$conn->id,
                'ok' => $ok,
                'label' => $conn->name.' ('.$tag.')',
                'detail' => $ok
                    ? ($latency !== null ? $latency.' ms' : 'OK')
                    : ($result['error'] ?? __('system.latency_error')),
            ];
            if (! $ok) {
                $hints[] = __('system.connection_no_clash_response', ['name' => $conn->name, 'error' => $result['error'] ?? __('system.no_clash_api_response')]);
            }
        }

        return $this->finalizeGroup('outbounds', 'Outbounds', $checks, $hints);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AwgConfig>  $configs
     * @return array<string, mixed>
     */
    private function groupVirtualNetworks($configs): array
    {
        $checks = [];
        $hints = [];
        $vns = $configs->where('type', 'virtual_network')->values();

        if ($vns->isEmpty()) {
            $checks[] = [
                'id' => 'vn_none',
                'ok' => true,
                'label' => __('system.virtual_networks_label'),
                'detail' => __('system.no_vn_in_selection'),
            ];

            return $this->finalizeGroup('vn', __('system.group_vn'), $checks, $hints);
        }

        $stats = $this->awg->peerStats();
        $peersByConfig = [];
        foreach ($stats['peers'] ?? [] as $peer) {
            $cid = (int) ($peer['config_id'] ?? 0);
            $peersByConfig[$cid][] = $peer;
        }

        foreach ($vns as $config) {
            $up = $config->enabled && $this->awg->isContainerRunning() && $this->ifaceIsUp($config->iface);
            $peerCount = AwgConfigPeer::query()->where('awg_config_id', $config->id)->count();
            $enabledPeers = AwgConfigPeer::query()
                ->where('awg_config_id', $config->id)
                ->where('enabled', true)
                ->count();
            $online = 0;
            foreach ($peersByConfig[$config->id] ?? [] as $peer) {
                if (! empty($peer['online'])) {
                    $online++;
                }
            }

            $detailParts = [
                $config->enabled ? 'enabled' : 'disabled',
                $up ? 'iface up' : 'iface down',
                'peers='.$peerCount.' (enabled='.$enabledPeers.')',
                'online≈'.$online,
            ];

            if (! $config->enabled) {
                $ok = true;
                $detailParts[] = __('system.skip_disabled');
            } elseif (! $up) {
                $ok = false;
            } elseif ($enabledPeers > 0 && $online === 0) {
                $ok = false;
                $detailParts[] = __('system.no_fresh_handshakes');
            } else {
                $ok = true;
            }

            $checks[] = [
                'id' => 'vn_'.$config->id,
                'ok' => $ok,
                'label' => $config->name.' ('.$config->iface.')',
                'detail' => implode(' · ', $detailParts),
            ];

            if ($config->enabled && ! $up) {
                $hints[] = __('system.vn_iface_not_up', ['name' => $config->name]);
            } elseif ($config->enabled && $enabledPeers > 0 && $online === 0) {
                $hints[] = __('system.vn_no_fresh_handshakes', ['name' => $config->name]);
            }
        }

        return $this->finalizeGroup('vn', __('system.group_vn'), $checks, $hints);
    }

    /**
     * @param  list<array{id:string,ok:bool,label:string,detail:string}>  $checks
     * @param  list<string>  $hints
     * @return array<string, mixed>
     */
    private function finalizeGroup(string $id, string $title, array $checks, array $hints): array
    {
        $oks = array_column($checks, 'ok');
        $allOk = $oks === [] || ! in_array(false, $oks, true);
        $anyOk = in_array(true, $oks, true);
        $anyFail = in_array(false, $oks, true);

        $status = $allOk ? 'success' : ($anyOk && $anyFail ? 'warning' : 'error');

        return [
            'id' => $id,
            'title' => $title,
            'ok' => $allOk,
            'status' => $status,
            'checks' => $checks,
            'hints' => $hints,
        ];
    }

    private function maskAwgConfText(string $text): string
    {
        $text = preg_replace('/^(PrivateKey\s*=\s*).+$/mi', '$1***', $text) ?? $text;

        return preg_replace('/^(PresharedKey\s*=\s*).+$/mi', '$1***', $text) ?? $text;
    }

    private function maskJsonSecrets(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::MASK_JSON_KEYS, true)) {
                $out[$key] = '***';

                continue;
            }
            $out[$key] = $this->maskJsonSecrets($value);
        }

        return $out;
    }

    /** @return array{config_bytes: int, outbound_count: ?int, running: bool} */
    private function pingProbeStatus(): array
    {
        $path = app(ResolverPaths::class)->singBoxPingConfigPath();
        $bytes = is_file($path) ? (int) filesize($path) : 0;
        $outboundCount = null;

        if ($bytes > 0) {
            $raw = @file_get_contents($path);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($json['outbounds'] ?? null)) {
                $outboundCount = count($json['outbounds']);
            }
        }

        return [
            'config_bytes' => $bytes,
            'outbound_count' => $outboundCount,
            'running' => app(PingProbeManager::class)->isRunning(),
        ];
    }
}
