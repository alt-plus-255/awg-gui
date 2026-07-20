<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\PeerStatsSyncService;
use App\Services\AmneziaWg\QrCodeService;
use App\Services\AmneziaWg\VpnUriService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfigController extends Controller
{
    public function __construct(
        private AmneziaWgService $awg,
        private PeerStatsSyncService $statsSync,
        private QrCodeService $qr,
        private VpnUriService $vpnUri,
    ) {}

    public function index()
    {
        $configs = AwgConfig::query()
            ->withCount(['peers'])
            ->orderBy('id')
            ->get()
            ->map(fn (AwgConfig $c) => $this->serializeConfig($c));

        return response()->json(['configs' => $configs]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'type' => ['required', Rule::in(['server', 'virtual_network'])],
            'vn_policy' => ['sometimes', Rule::in(['allow_all', 'deny_all'])],
            'internal_subnet' => ['sometimes', 'string', 'max:64'],
            'listen_port' => ['sometimes', 'integer', 'min:51820', 'max:51839'],
            'peer_dns' => ['sometimes', 'string', 'max:255'],
            'client_allowed_ips' => ['sometimes', 'string', 'max:255'],
            'persistent_keepalive' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $alloc = $this->awg->allocateIfaceAndPort();
        $keys = $this->awg->generateKeyPair();
        $junk = $this->awg->generateJunkParams();
        $defaults = $this->awg->defaultConfigAttributes();

        $subnet = $data['internal_subnet'] ?? $defaults['internal_subnet'];
        $serverAddress = $defaults['server_address'];
        if (preg_match('#^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$#', $subnet, $m)) {
            $serverAddress = $m[1].'.1/'.$m[3];
        }

        $port = $data['listen_port'] ?? $alloc['listen_port'];
        if (AwgConfig::query()->where('listen_port', $port)->exists()) {
            throw ValidationException::withMessages([
                'listen_port' => ['Порт уже занят'],
            ]);
        }

        $config = AwgConfig::query()->create(array_merge($junk, [
            'name' => $data['name'],
            'type' => $data['type'],
            'vn_policy' => $data['vn_policy'] ?? 'allow_all',
            'iface' => $alloc['iface'],
            'listen_port' => $port,
            'internal_subnet' => $subnet,
            'server_address' => $serverAddress,
            'server_private_key' => $keys['private'],
            'server_public_key' => $keys['public'],
            'peer_dns' => $data['peer_dns'] ?? $defaults['peer_dns'],
            'client_allowed_ips' => $data['client_allowed_ips'] ?? $defaults['client_allowed_ips'],
            'persistent_keepalive' => $data['persistent_keepalive'] ?? $defaults['persistent_keepalive'],
            'enabled' => $data['enabled'] ?? true,
        ]));

        $this->awg->applyConfig();

        return response()->json(['config' => $this->serializeConfig($config->fresh())], 201);
    }

    public function show(AwgConfig $config)
    {
        $config->loadCount('peers');

        return response()->json(['config' => $this->serializeConfig($config)]);
    }

    public function update(Request $request, AwgConfig $config)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64'],
            'type' => ['sometimes', Rule::in(['server', 'virtual_network'])],
            'vn_policy' => ['sometimes', Rule::in(['allow_all', 'deny_all'])],
            'internal_subnet' => ['sometimes', 'string', 'max:64'],
            'server_address' => ['sometimes', 'string', 'max:64'],
            'listen_port' => ['sometimes', 'integer', 'min:51820', 'max:51839', Rule::unique('awg_configs', 'listen_port')->ignore($config->id)],
            'peer_dns' => ['sometimes', 'string', 'max:255'],
            'client_allowed_ips' => ['sometimes', 'string', 'max:255'],
            'persistent_keepalive' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'enabled' => ['sometimes', 'boolean'],
            'jc' => ['sometimes', 'string', 'max:10'],
            'jmin' => ['sometimes', 'string', 'max:10'],
            'jmax' => ['sometimes', 'string', 'max:10'],
            's1' => ['sometimes', 'string', 'max:10'],
            's2' => ['sometimes', 'string', 'max:10'],
            's3' => ['sometimes', 'string', 'max:10'],
            's4' => ['sometimes', 'string', 'max:10'],
            'h1' => ['sometimes', 'string', 'max:20'],
            'h2' => ['sometimes', 'string', 'max:20'],
            'h3' => ['sometimes', 'string', 'max:20'],
            'h4' => ['sometimes', 'string', 'max:20'],
            'i1' => ['nullable', 'string', 'max:2048'],
            'i2' => ['nullable', 'string', 'max:2048'],
            'i3' => ['nullable', 'string', 'max:2048'],
            'i4' => ['nullable', 'string', 'max:2048'],
            'i5' => ['nullable', 'string', 'max:2048'],
        ]);

        $data = $this->sanitizeSignatureFields($data);

        $config->fill($data);

        if (($config->type === 'virtual_network') && $config->resolver_enabled) {
            $config->resolver_enabled = false;
            $config->connection_id = null;
            $config->resolver_last_error = null;
        }

        if (isset($data['internal_subnet']) && ! isset($data['server_address'])) {
            $this->awg->syncServerAddressFromSubnet($config);
        } else {
            $config->save();
        }

        $this->awg->applyConfig();

        return response()->json(['config' => $this->serializeConfig($config->fresh())]);
    }

    public function destroy(AwgConfig $config)
    {
        if (AwgConfig::query()->count() <= 1) {
            throw ValidationException::withMessages([
                'config' => ['Нельзя удалить последний конфиг'],
            ]);
        }

        $config->delete();
        $this->awg->applyConfig();

        return response()->json(['ok' => true]);
    }

    public function peers(AwgConfig $config)
    {
        $this->awg->primeConfigPeerCache($config);

        $peers = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->with('client')
            ->orderBy('id')
            ->get()
            ->map(fn (AwgConfigPeer $m) => $this->serializeMembership($config, $m));

        return response()->json(['peers' => $peers]);
    }

    public function links(AwgConfig $config)
    {
        return response()->json([
            'links' => $this->awg->peerLinks($config->id),
        ]);
    }

    public function attachPeer(Request $request, AwgConfig $config)
    {
        $data = $request->validate([
            'vpn_client_id' => ['required', 'integer', Rule::exists('vpn_clients', 'id')],
            'enabled' => ['sometimes', 'boolean'],
            'use_preshared_key' => ['sometimes', 'boolean'],
            'extra_allowed_ips' => ['sometimes', 'array'],
            'extra_allowed_ips.*' => ['string', 'max:64'],
            'excluded_client_ids' => ['sometimes', 'array'],
            'excluded_client_ids.*' => ['integer'],
            'exclusions_mutual' => ['sometimes', 'boolean'],
            'keepalive' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $client = VpnClient::query()->findOrFail($data['vpn_client_id']);

        if (AwgConfigPeer::query()->where('awg_config_id', $config->id)->where('vpn_client_id', $client->id)->exists()) {
            throw ValidationException::withMessages([
                'vpn_client_id' => ['Peer уже привязан к этому конфигу'],
            ]);
        }

        $extraIps = $this->normalizeExtraIps($data['extra_allowed_ips'] ?? []);
        $this->assertVirtualNetworkSubnet($config, $extraIps);
        $excludedIds = $this->normalizeExcludedClientIds($config, $client->id, $data['excluded_client_ids'] ?? []);

        $keys = $this->awg->generateKeyPair();
        $usePsk = $data['use_preshared_key'] ?? true;

        $membership = AwgConfigPeer::query()->create([
            'awg_config_id' => $config->id,
            'vpn_client_id' => $client->id,
            'enabled' => $data['enabled'] ?? true,
            'private_key' => $keys['private'],
            'public_key' => $keys['public'],
            'preshared_key' => $usePsk ? $this->awg->generatePresharedKey() : null,
            'address' => $this->awg->nextClientAddress($config),
            'extra_allowed_ips' => $extraIps,
            'excluded_client_ids' => $excludedIds,
            'exclusions_mutual' => $data['exclusions_mutual'] ?? false,
            'keepalive' => $data['keepalive'] ?? null,
        ]);

        $this->awg->applyConfig($config, withResolver: false);

        return response()->json([
            'membership' => $this->serializeMembership($config, $membership->load('client')),
        ], 201);
    }

    public function updatePeer(Request $request, AwgConfig $config, VpnClient $client)
    {
        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->firstOrFail();

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'use_preshared_key' => ['sometimes', 'boolean'],
            'extra_allowed_ips' => ['sometimes', 'array'],
            'extra_allowed_ips.*' => ['string', 'max:64'],
            'excluded_client_ids' => ['sometimes', 'array'],
            'excluded_client_ids.*' => ['integer'],
            'exclusions_mutual' => ['sometimes', 'boolean'],
            'keepalive' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        if (array_key_exists('enabled', $data)) {
            $membership->enabled = $data['enabled'];
        }
        if (array_key_exists('keepalive', $data)) {
            $membership->keepalive = $data['keepalive'];
        }
        if (array_key_exists('extra_allowed_ips', $data)) {
            $extraIps = $this->normalizeExtraIps($data['extra_allowed_ips']);
            $this->assertVirtualNetworkSubnet($config, $extraIps);
            $membership->extra_allowed_ips = $extraIps;
        }
        if (array_key_exists('excluded_client_ids', $data)) {
            $membership->excluded_client_ids = $this->normalizeExcludedClientIds($config, $client->id, $data['excluded_client_ids']);
        }
        if (array_key_exists('exclusions_mutual', $data)) {
            $membership->exclusions_mutual = $data['exclusions_mutual'];
        }
        if (array_key_exists('use_preshared_key', $data)) {
            if ($data['use_preshared_key']) {
                if (! $membership->preshared_key) {
                    $membership->preshared_key = $this->awg->generatePresharedKey();
                }
            } else {
                $membership->preshared_key = null;
            }
        }

        $membership->save();
        $this->awg->applyConfig($config, withResolver: false);

        return response()->json([
            'membership' => $this->serializeMembership($config, $membership->load('client')),
        ]);
    }

    public function detachPeer(AwgConfig $config, VpnClient $client)
    {
        AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->delete();

        $this->pruneExcludedClientId($config, $client->id);
        $this->pruneClientFromZones($config, $client->id);

        $this->awg->applyConfig($config, withResolver: false);

        return response()->json(['ok' => true]);
    }

    /** Обновляет правила доступа VN-конфига (политика deny_all). */
    public function updateZones(Request $request, AwgConfig $config)
    {
        if ($config->type !== 'virtual_network') {
            throw ValidationException::withMessages([
                'config' => ['Правила доступа доступны только для конфигов типа «Виртуальная сеть»'],
            ]);
        }

        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.src_client_ids' => ['present', 'array'],
            'rules.*.src_client_ids.*' => ['integer'],
            'rules.*.dest_client_ids' => ['present', 'array'],
            'rules.*.dest_client_ids.*' => ['integer'],
        ]);

        $config->vn_zones = $this->normalizeRules($config, $data['rules']);
        $config->save();

        $this->awg->applyConfig();

        return response()->json(['config' => $this->serializeConfig($config->fresh()->loadCount('peers'))]);
    }

    public function serverConfig(AwgConfig $config)
    {
        $conf = $this->awg->buildServerConfig($config);
        $filename = ($config->iface ?: 'awg').'.conf';

        return response($conf, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function peerConfig(AwgConfig $config, VpnClient $client)
    {
        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->with('client')
            ->firstOrFail();

        $conf = $this->awg->buildClientConfig($membership);
        $conf = $this->qr->normalizeConfigText($conf);
        $filename = ($membership->client?->name ?? 'peer').'-'.$config->name.'.conf';

        return response($conf, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function peerVpnUri(AwgConfig $config, VpnClient $client)
    {
        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->with('client')
            ->firstOrFail();

        $uri = $this->vpnUri->buildFromMembership($membership);

        return response($uri, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    public function peerQr(Request $request, AwgConfig $config, VpnClient $client)
    {
        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->firstOrFail();

        $conf = $this->awg->buildClientConfig($membership);
        $conf = $this->qr->normalizeConfigText($conf);
        $format = $request->query('format', 'svg');

        if ($format === 'png') {
            $body = $this->qr->buildPng($conf);

            return response($body, 200, [
                'Content-Type' => 'image/png',
            ]);
        }

        $body = $this->qr->buildSvg($conf);

        return response($body, 200, [
            'Content-Type' => 'image/svg+xml',
        ]);
    }

    public function regeneratePeerKeys(AwgConfig $config, VpnClient $client)
    {
        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->firstOrFail();

        $keys = $this->awg->generateKeyPair();
        $membership->private_key = $keys['private'];
        $membership->public_key = $keys['public'];
        $membership->save();
        $this->awg->applyConfig();

        return response()->json([
            'membership' => $this->serializeMembership($config, $membership->load('client')),
        ]);
    }

    public function regeneratePeerPsk(AwgConfig $config, VpnClient $client)
    {
        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->firstOrFail();

        if (! $membership->preshared_key) {
            throw ValidationException::withMessages([
                'use_preshared_key' => ['PresharedKey выключен для этого peer'],
            ]);
        }

        $membership->preshared_key = $this->awg->generatePresharedKey();
        $membership->save();
        $this->awg->applyConfig();

        return response()->json([
            'membership' => $this->serializeMembership($config, $membership->load('client')),
        ]);
    }

    public function revealPeerKeys(Request $request, AwgConfig $config, VpnClient $client)
    {
        $this->assertAdminPassword($request);

        $membership = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('vpn_client_id', $client->id)
            ->firstOrFail();

        return response()->json([
            'private_key' => $membership->private_key,
            'public_key' => $membership->public_key,
            'preshared_key' => $membership->preshared_key,
            'use_preshared_key' => (bool) $membership->preshared_key,
        ]);
    }

    public function regenerateServerKeys(AwgConfig $config)
    {
        $result = $this->awg->regenerateConfigKeys($config);

        return response()->json([
            'ok' => true,
            'server_public_key' => $result['server_public_key'],
            'config' => $this->serializeConfig($config->fresh()),
            'message' => 'Ключи сервера перегенерированы. Скачайте заново конфиги всех peer.',
        ]);
    }

    public function regenerateJunk(AwgConfig $config)
    {
        $junk = $this->awg->regenerateConfigJunk($config);

        return response()->json([
            'junk' => $junk,
            'config' => $this->serializeConfig($config->fresh()),
        ]);
    }

    public function revealServerKey(Request $request, AwgConfig $config)
    {
        $this->assertAdminPassword($request);

        return response()->json([
            'server_private_key' => $config->server_private_key,
            'server_public_key' => $config->server_public_key,
        ]);
    }

    public function restart(AwgConfig $config)
    {
        $result = $this->awg->restartAwg();

        if (! empty($result['already_restarting'])) {
            return response()->json([
                'ok' => false,
                'already_restarting' => true,
                'message' => 'Перезапуск AWG уже выполняется',
                'details' => $result,
            ], 409);
        }

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => 'Не удалось перезапустить контейнер AmneziaWG',
                'details' => $result,
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Контейнер AmneziaWG перезапущен, конфиги применены',
            'details' => $result,
        ]);
    }

    private function assertAdminPassword(Request $request): void
    {
        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Неверный пароль администратора'],
            ]);
        }
    }

    /** @param  list<string>  $extraIps */
    private function assertVirtualNetworkSubnet(AwgConfig $config, array $extraIps): void
    {
        if ($config->type !== 'virtual_network') {
            return;
        }

        if (count($extraIps) !== 1) {
            throw ValidationException::withMessages([
                'extra_allowed_ips' => ['Для виртуальной сети укажите ровно одну локальную подсеть (например 192.168.1.0/24)'],
            ]);
        }
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function normalizeExcludedClientIds(AwgConfig $config, int $ownClientId, array $ids): array
    {
        if ($config->type !== 'virtual_network') {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }

        if (in_array($ownClientId, $ids, true)) {
            throw ValidationException::withMessages([
                'excluded_client_ids' => ['Нельзя исключить самого себя'],
            ]);
        }

        $attachedIds = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->pluck('vpn_client_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $unknown = array_diff($ids, $attachedIds);
        if ($unknown) {
            throw ValidationException::withMessages([
                'excluded_client_ids' => ['Некоторые исключаемые узлы не привязаны к этому конфигу'],
            ]);
        }

        return $ids;
    }

    /**
     * Валидирует и нормализует правила доступа «источники → назначения»:
     * участники привязаны к конфигу, пир не может быть в обоих полях одного правила,
     * пустые и дублирующиеся правила отбрасываются.
     *
     * @param  list<array{src_client_ids:list<int>,dest_client_ids:list<int>}>  $rules
     * @return array{rules:list<array{src_client_ids:list<int>,dest_client_ids:list<int>}>}
     */
    private function normalizeRules(AwgConfig $config, array $rules): array
    {
        $attachedIds = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->pluck('vpn_client_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $outRules = [];
        $seen = [];
        foreach ($rules as $rule) {
            // молча отбрасываем id узлов, уже не привязанных к конфигу (например, удалённых клиентов)
            $src = array_values(array_intersect(
                array_unique(array_map('intval', $rule['src_client_ids'])),
                $attachedIds
            ));
            $dest = array_values(array_intersect(
                array_unique(array_map('intval', $rule['dest_client_ids'])),
                $attachedIds
            ));

            if (! $src || ! $dest) {
                continue;
            }

            if (array_intersect($src, $dest)) {
                throw ValidationException::withMessages([
                    'rules' => ['Пир не может быть одновременно источником и назначением в одном правиле'],
                ]);
            }

            sort($src);
            sort($dest);
            $key = implode(',', $src).'→'.implode(',', $dest);
            if (in_array($key, $seen, true)) {
                continue;
            }
            $seen[] = $key;

            $outRules[] = [
                'src_client_ids' => $src,
                'dest_client_ids' => $dest,
            ];
        }

        return ['rules' => $outRules];
    }

    /** Убирает id отвязанного клиента из правил доступа конфига. */
    private function pruneClientFromZones(AwgConfig $config, int $clientId): void
    {
        $vnZones = $config->vn_zones;
        if (! is_array($vnZones) || empty($vnZones['rules'])) {
            return;
        }

        $changed = false;
        $rules = [];
        foreach ($vnZones['rules'] as $rule) {
            $src = array_map('intval', $rule['src_client_ids'] ?? []);
            $dest = array_map('intval', $rule['dest_client_ids'] ?? []);
            if (in_array($clientId, $src, true) || in_array($clientId, $dest, true)) {
                $changed = true;
                $src = array_values(array_diff($src, [$clientId]));
                $dest = array_values(array_diff($dest, [$clientId]));
            }
            // правило без источников или назначений теряет смысл
            if ($src && $dest) {
                $rules[] = ['src_client_ids' => $src, 'dest_client_ids' => $dest];
            }
        }

        if ($changed) {
            $vnZones['rules'] = $rules;
            $config->vn_zones = $vnZones;
            $config->save();
        }
    }

    /** Убирает id отвязанного клиента из исключений остальных пиров конфига. */
    private function pruneExcludedClientId(AwgConfig $config, int $clientId): void
    {
        $memberships = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->whereNotNull('excluded_client_ids')
            ->get();

        foreach ($memberships as $membership) {
            $excluded = array_map('intval', $membership->excluded_client_ids ?? []);
            if (in_array($clientId, $excluded, true)) {
                $membership->excluded_client_ids = array_values(array_diff($excluded, [$clientId]));
                $membership->save();
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    private function sanitizeSignatureFields(array $data): array
    {
        foreach (['i1', 'i2', 'i3', 'i4', 'i5'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $val = trim((string) ($data[$key] ?? ''));
            $data[$key] = $val === '' ? null : $val;
        }

        return $data;
    }

    /** @param  list<string>|null  $ips */
    private function normalizeExtraIps(?array $ips): array
    {
        if (! $ips) {
            return [];
        }

        $out = [];
        foreach ($ips as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '') {
                continue;
            }
            if (! preg_match('#^[^/\s]+/\d{1,3}$#', $cidr)) {
                throw ValidationException::withMessages([
                    'extra_allowed_ips' => ["Неверный CIDR: {$cidr}"],
                ]);
            }
            $host = explode('/', $cidr, 2)[0];
            if (! filter_var($host, FILTER_VALIDATE_IP)) {
                throw ValidationException::withMessages([
                    'extra_allowed_ips' => ["Неверный IP в CIDR: {$cidr}"],
                ]);
            }
            $out[] = $cidr;
        }

        return array_values(array_unique($out));
    }

    private function serializeConfig(AwgConfig $config): array
    {
        return [
            'id' => $config->id,
            'name' => $config->name,
            'type' => $config->type,
            'type_label' => $config->type === 'virtual_network' ? 'Виртуальная сеть' : 'Сервер',
            'vn_policy' => $config->vn_policy ?? 'allow_all',
            'vn_zones' => [
                'rules' => array_values($config->vn_zones['rules'] ?? []),
            ],
            'iface' => $config->iface,
            'listen_port' => $config->listen_port,
            'internal_subnet' => $config->internal_subnet,
            'server_address' => $config->server_address,
            'server_public_key' => $config->server_public_key,
            'peer_dns' => $config->peer_dns,
            'client_allowed_ips' => $config->client_allowed_ips,
            'persistent_keepalive' => $config->persistent_keepalive,
            'enabled' => $config->enabled,
            'resolver_enabled' => (bool) $config->resolver_enabled,
            'connection_id' => $config->connection_id,
            'community_lists' => array_values($config->community_lists ?? []),
            'user_domains' => array_values($config->user_domains ?? []),
            'user_subnets' => array_values($config->user_subnets ?? []),
            'resolver_updated_at' => optional($config->resolver_updated_at)?->toIso8601String(),
            'resolver_last_error' => $config->resolver_last_error,
            'config_path' => $this->awg->configPath($config),
            'host_config_path' => $this->awg->hostConfigPath($config),
            'peers_count' => $config->peers_count ?? $config->peers()->count(),
            'jc' => $config->jc,
            'jmin' => $config->jmin,
            'jmax' => $config->jmax,
            's1' => $config->s1,
            's2' => $config->s2,
            's3' => $config->s3,
            's4' => $config->s4,
            'h1' => $config->h1,
            'h2' => $config->h2,
            'h3' => $config->h3,
            'h4' => $config->h4,
            'i1' => $config->i1,
            'i2' => $config->i2,
            'i3' => $config->i3,
            'i4' => $config->i4,
            'i5' => $config->i5,
            'created_at' => $config->created_at,
            'updated_at' => $config->updated_at,
        ];
    }

    private function serializeMembership(AwgConfig $config, AwgConfigPeer $membership): array
    {
        return $this->statsSync->serializePeer($config, $membership);
    }
}
