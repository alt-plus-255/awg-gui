<?php

namespace App\Services\Diagnostics;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\ResolverConnection;
use App\Models\Setting;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Resolver\PingProbeManager;
use App\Services\Resolver\ResolverPaths;
use App\Services\Resolver\ResolverService;
use Illuminate\Support\Facades\Process;

class DiagnosticsService
{
    private const CONTAINERS = [
        ['name' => 'awggui-awg', 'label' => 'AmneziaWG'],
        ['name' => 'awggui-app', 'label' => 'Панель (API)'],
        ['name' => 'awggui-db', 'label' => 'MariaDB'],
        ['name' => 'awggui-caddy', 'label' => 'Caddy'],
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
                'type_label' => $c->type === 'virtual_network' ? 'Виртуальная сеть' : 'Сервер',
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
                'error' => 'sing-box.json не найден',
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
                'error' => 'Некорректный JSON — показан сырой текст с маскированием',
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
                    ? ($cfg->type === 'virtual_network' ? 'Виртуальная сеть' : 'Сервер')
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
                'label' => $c['label'],
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
        $detail = 'Контейнер AWG не запущен';
        if ($this->awg->isContainerRunning()) {
            try {
                $r = Process::timeout(10)->run([
                    'docker', 'exec', $this->awg->containerName(),
                    'sh', '-c', 'pgrep -x sing-box >/dev/null && echo yes || echo no',
                ]);
                $running = trim($r->output()) === 'yes';
                $detail = $running ? 'процесс запущен' : 'процесс не найден';
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
                    'detail' => 'Контейнер AWG не запущен',
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
                'detail' => $up ? 'up' : 'down / не найден',
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
            $r = Process::timeout(8)->run([
                'docker', 'exec', $this->awg->containerName(),
                'sh', '-c', 'ip link show '.$iface.' >/dev/null 2>&1 && echo yes || echo no',
            ]);

            return trim($r->output()) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    private function awgShowAvailable(string $iface): bool
    {
        try {
            $r = Process::timeout(8)->run([
                'docker', 'exec', $this->awg->containerName(),
                'awg', 'show', $iface,
            ]);

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
                $hints[] = 'Контейнер '.$c['name'].' не запущен — проверьте docker compose.';
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
            $hints[] = 'sing-box не запущен — примените резолвер или перезапустите AWG.';
        }

        return $this->finalizeGroup('runtime', 'Контейнеры / runtime', $checks, $hints);
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
                'label' => 'Интерфейсы AWG',
                'detail' => 'Нет включённых конфигов в выборке',
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
                    : ($showOk ? 'up · awg show OK' : 'up, но awg show недоступен'),
            ];
            if (! $up) {
                $hints[] = 'Интерфейс '.$config->iface.' не поднят — перезапустите AWG или проверьте конфиг «'.$config->name.'».';
            } elseif (! $showOk) {
                $hints[] = 'awg show '.$config->iface.' не отвечает — проверьте userspace/amneziawg.';
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
                'label' => 'Резолвер',
                'detail' => $anyServer
                    ? 'В выборке нет серверов с включённым резолвером — пропуск'
                    : 'Нет server-конфигов в выборке — пропуск',
            ]];

            return $this->finalizeGroup('resolver', 'Резолвер / DNS / FakeIP', $checks, []);
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
            'Резолвер / DNS / FakeIP',
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
                    'detail' => 'Нет активных подключений резолвера',
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
                'detail' => 'Контейнер AWG не запущен',
            ];

            return $this->finalizeGroup('outbounds', 'Outbounds', $checks, ['Запустите контейнер AmneziaWG для проверки outbounds.']);
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
                    : ($result['error'] ?? 'ошибка latency'),
            ];
            if (! $ok) {
                $hints[] = 'Подключение «'.$conn->name.'»: '.($result['error'] ?? 'нет ответа Clash API');
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
                'label' => 'Виртуальные сети',
                'detail' => 'Нет VN в выборке — пропуск',
            ];

            return $this->finalizeGroup('vn', 'Виртуальные сети', $checks, $hints);
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
                $detailParts[] = 'пропуск (выключен)';
            } elseif (! $up) {
                $ok = false;
            } elseif ($enabledPeers > 0 && $online === 0) {
                $ok = false;
                $detailParts[] = 'нет свежих handshake';
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
                $hints[] = 'VN «'.$config->name.'»: интерфейс не поднят.';
            } elseif ($config->enabled && $enabledPeers > 0 && $online === 0) {
                $hints[] = 'VN «'.$config->name.'»: нет свежих handshake у пиров (подключите клиентов или проверьте ключи).';
            }
        }

        return $this->finalizeGroup('vn', 'Виртуальные сети', $checks, $hints);
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
