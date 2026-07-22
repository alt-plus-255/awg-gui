<?php

namespace App\Services\AmneziaWg;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\Setting;
use App\Models\VpnClient;
use App\Services\Resolver\ResolverService;
use App\Services\Docker\DockerRuntime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AmneziaWgService
{
    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, AwgConfigPeer>> */
    private array $enabledPeersCache = [];

    /** @var array<string, string> */
    private array $clientAllowedIpsStringCache = [];

    public function __construct(private DockerRuntime $docker) {}

    public function primeConfigPeerCache(AwgConfig $config): void
    {
        $this->enabledPeersForConfig($config);
    }

    public const PORT_MIN = 51820;

    public const PORT_MAX = 51839;

    private const RESTART_LOCK_KEY = 'awg_restarting';

    /** Safety TTL if the PHP process dies mid-restart (seconds). */
    private const RESTART_LOCK_TTL = 120;

    public function configDir(): string
    {
        return rtrim(env('AWG_CONFIG_DIR', '/awg'), '/');
    }

    public function configPath(AwgConfig $config): string
    {
        return $this->configDir().'/'.$config->iface.'.conf';
    }

    public function hostConfigDir(): string
    {
        return rtrim(env('HOST_AWG_CONFIG_DIR', '/var/lib/docker/volumes/awggui_awg_config/_data'), '/');
    }

    public function hostConfigPath(AwgConfig $config): string
    {
        return $this->hostConfigDir().'/'.$config->iface.'.conf';
    }

    public function containerName(): string
    {
        return env('AWG_CONTAINER', 'awggui-awg');
    }

    public function isContainerRunning(?string $name = null): bool
    {
        $name = $name ?? $this->containerName();
        $result = $this->docker->run(['inspect', '-f', '{{.State.Running}}', $name]);

        return $result->successful() && trim($result->output()) === 'true';
    }

    public function probeStatsAvailable(): bool
    {
        $config = AwgConfig::query()->where('enabled', true)->orderBy('id')->first();
        if (! $config) {
            return true;
        }

        return $this->dumpStatsForIface($config->iface)['available'];
    }

    public function applyAfterClientChange(VpnClient $client): void
    {
        $configIds = AwgConfigPeer::query()
            ->where('vpn_client_id', $client->id)
            ->pluck('awg_config_id')
            ->unique();

        if ($configIds->isEmpty()) {
            $this->applyConfig();

            return;
        }

        foreach ($configIds as $configId) {
            $config = AwgConfig::query()->find($configId);
            if ($config) {
                $this->applyConfig($config, withResolver: false);
            }
        }
    }

    public function hostGuiDir(): string
    {
        return rtrim(env('HOST_AWG_GUI_DIR', '/host-awg-gui'), '/');
    }

    /**
     * AmneziaWG uses WireGuard-compatible Curve25519 keys plus obfuscation params (Jc/H/S/I).
     * Prefer awg-tools inside the AWG container; fall back to PHP when it is not up yet.
     *
     * @return array{private:string,public:string}
     */
    public function generateKeyPair(): array
    {
        return $this->generateKeyPairViaAwg() ?? $this->generateKeyPairViaSodium();
    }

    /** @return array{private:string,public:string}|null */
    private function generateKeyPairViaAwg(): ?array
    {
        if (! $this->isContainerRunning()) {
            return null;
        }

        $privateResult = $this->docker->exec(
            $this->containerName(),
            ['awg', 'genkey'],
            timeout: 10,
        );
        if (! $privateResult->successful()) {
            return null;
        }

        $private = trim($privateResult->output());
        if ($private === '') {
            return null;
        }

        $publicResult = $this->docker->execInteractive(
            $this->containerName(),
            ['awg', 'pubkey'],
            timeout: 10,
            input: $private."\n",
        );
        if (! $publicResult->successful()) {
            return null;
        }

        $public = trim($publicResult->output());
        if ($public === '') {
            return null;
        }

        return [
            'private' => $private,
            'public' => $public,
        ];
    }

    /** @return array{private:string,public:string} */
    private function generateKeyPairViaSodium(): array
    {
        $private = random_bytes(32);
        $private[0] = chr(ord($private[0]) & 248);
        $private[31] = chr((ord($private[31]) & 127) | 64);
        $public = sodium_crypto_scalarmult_base($private);

        return [
            'private' => base64_encode($private),
            'public' => base64_encode($public),
        ];
    }

    public function generatePresharedKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return [
            'server_endpoint' => env('SERVER_ENDPOINT', 'auto'),
            'panel_domain' => '',
            'endpoint_use_domain' => '0',
            'panel_port' => (string) env('PANEL_PORT', '8877'),
            'panel_https_port' => (string) env('PANEL_HTTPS_PORT', '7443'),
            'ssl_email' => '',
            'ssl_enabled' => '0',
            'ssl_status' => 'disabled',
            'ssl_error' => '',
            'ssl_expires_at' => '',
            'failure_webhook_url' => '',
            'timezone' => (string) env('TZ', 'UTC'),
        ];
    }

    public function resolveTimezone(): string
    {
        $tz = trim((string) Setting::getValue('timezone', env('TZ', 'UTC')));
        if ($tz === '' || ! in_array($tz, timezone_identifiers_list(), true)) {
            return 'UTC';
        }

        return $tz;
    }

    public function applyTimezone(?string $timezone = null): string
    {
        $tz = $timezone ?? $this->resolveTimezone();
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            $tz = 'UTC';
        }
        config(['app.timezone' => $tz]);
        date_default_timezone_set($tz);

        return $tz;
    }

    /**
     * Best-effort sync of TZ into the host compose .env (if reachable).
     */
    public function syncTimezoneToHostEnv(string $timezone): void
    {
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            return;
        }

        $candidates = [];
        $conf = $this->hostGuiDir().'/awg-gui.conf';
        if (is_readable($conf)) {
            foreach (file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'ENV_FILE=')) {
                    $candidates[] = substr($line, strlen('ENV_FILE='));
                }
            }
        }
        $candidates[] = base_path('../.env');

        foreach (array_unique(array_filter($candidates)) as $path) {
            if (! is_writable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            if (preg_match('/^TZ=.*/m', $raw)) {
                $raw = preg_replace('/^TZ=.*/m', 'TZ='.$timezone, $raw, 1);
            } else {
                $raw = rtrim($raw)."\nTZ=".$timezone."\n";
            }
            @file_put_contents($path, $raw);
            break;
        }
    }

    /** @return array<string, string> */
    public function defaultConfigAttributes(): array
    {
        $subnet = env('INTERNAL_SUBNET', '10.66.66.0/24');
        $serverAddress = '10.66.66.1/24';
        if (preg_match('#^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$#', $subnet, $m)) {
            $serverAddress = $m[1].'.1/'.$m[3];
        }

        return [
            'type' => 'server',
            'internal_subnet' => $subnet,
            'server_address' => $serverAddress,
            'peer_dns' => env('PEER_DNS', '1.1.1.1'),
            'client_allowed_ips' => env('ALLOWED_IPS', '0.0.0.0/0, ::/0'),
            'persistent_keepalive' => (int) env('PERSISTENT_KEEPALIVE', 25),
            'enabled' => true,
        ];
    }

    public function usesDomainInEndpoint(): bool
    {
        return filter_var(Setting::getValue('endpoint_use_domain', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Host stored as public server address (IP / auto), ignoring domain preference.
     */
    public function resolveServerEndpointHost(): string
    {
        $endpointHost = Setting::getValue('server_endpoint', 'auto');
        if ($endpointHost === 'auto' || $endpointHost === '') {
            $endpointHost = request()?->getHost() ?: (gethostname() ?: '127.0.0.1');
        }

        return (string) $endpointHost;
    }

    /**
     * Host written into client VPN Endpoint (IP or panel domain by setting).
     */
    public function resolveEndpointHost(): string
    {
        $domain = $this->resolvePanelDomain();
        if ($this->usesDomainInEndpoint() && $domain !== '') {
            return $domain;
        }

        return $this->resolveServerEndpointHost();
    }

    public function resolvePanelDomain(): string
    {
        return trim((string) Setting::getValue('panel_domain', ''));
    }

    public function resolvePanelHost(): string
    {
        $domain = $this->resolvePanelDomain();

        return $domain !== '' ? $domain : $this->resolveServerEndpointHost();
    }

    /** @return list<string> */
    public function resolveIpv4Addresses(string $host): array
    {
        $host = trim($host);
        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [$host];
        }

        $ips = [];
        $records = @dns_get_record($host, DNS_A);
        if (is_array($records)) {
            foreach ($records as $rec) {
                $ip = (string) ($rec['ip'] ?? '');
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $ips[] = $ip;
                }
            }
        }

        $fallback = gethostbyname($host);
        if (is_string($fallback) && $fallback !== $host && filter_var($fallback, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips[] = $fallback;
        }

        return array_values(array_unique($ips));
    }

    /**
     * Ensure domain A-records point to the panel public IPv4.
     *
     * @throws \InvalidArgumentException
     */
    public function assertDomainPointsToPublicIp(string $domain, string $publicHost): void
    {
        $domain = trim($domain);
        $publicHost = trim($publicHost);

        if ($domain === '') {
            return;
        }

        if ($publicHost === '' || $publicHost === 'auto') {
            throw new \InvalidArgumentException(
                __('settings.domain_check_need_public_ipv4')
            );
        }

        if (! filter_var($publicHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException(
                __('settings.public_ip_must_be_ipv4')
            );
        }

        $resolved = $this->resolveIpv4Addresses($domain);
        if ($resolved === []) {
            throw new \InvalidArgumentException(
                __('settings.domain_no_a_record', ['domain' => $domain])
            );
        }

        if (! in_array($publicHost, $resolved, true)) {
            $got = implode(', ', $resolved);
            throw new \InvalidArgumentException(
                __('settings.domain_points_elsewhere', ['domain' => $domain, 'got' => $got, 'host' => $publicHost])
            );
        }
    }

    /** @return list<string> */
    public function resolveSanctumStatefulDomains(): array
    {
        $port = (string) Setting::getValue('panel_port', env('PANEL_PORT', '8877'));
        $httpsPort = $this->resolvePanelHttpsPort();
        $domains = [];

        foreach (['localhost', '127.0.0.1', '::1'] as $host) {
            $domains[] = $host;
            $domains[] = "{$host}:{$port}";
            $domains[] = "{$host}:{$httpsPort}";
        }

        $endpoint = $this->resolveServerEndpointHost();
        if ($endpoint !== '' && $endpoint !== 'auto') {
            $domains[] = $endpoint;
            $domains[] = "{$endpoint}:{$port}";
            $domains[] = "{$endpoint}:{$httpsPort}";
        }

        $panelDomain = $this->resolvePanelDomain();
        if ($panelDomain !== '') {
            $domains[] = $panelDomain;
            $domains[] = "{$panelDomain}:{$port}";
            $domains[] = "{$panelDomain}:{$httpsPort}";
        }

        $envDomains = array_filter(array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))));
        $domains = array_merge($domains, $envDomains);

        // Let Sanctum match whatever Host the browser actually uses.
        if (class_exists(\Laravel\Sanctum\Sanctum::class)) {
            $domains[] = \Laravel\Sanctum\Sanctum::$currentRequestHostPlaceholder;
        }

        $badPlaceholder = class_exists(\Laravel\Sanctum\Sanctum::class)
            ? ','.\Laravel\Sanctum\Sanctum::$currentRequestHostPlaceholder
            : null;

        return array_values(array_unique(array_filter(
            $domains,
            static fn (string $domain): bool => $domain !== '' && $domain !== $badPlaceholder
        )));
    }

    /** @return list<string> */
    public function serverPeerAllowedIps(AwgConfigPeer $membership): array
    {
        $ips = [$membership->address];
        $extras = $membership->extra_allowed_ips ?? [];
        if (! is_array($extras)) {
            $extras = [];
        }
        foreach ($extras as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '' || $cidr === $membership->address) {
                continue;
            }
            $ips[] = $cidr;
        }

        return array_values(array_unique($ips));
    }

    public function serverPeerAllowedIpsString(AwgConfigPeer $membership): string
    {
        return implode(', ', $this->serverPeerAllowedIps($membership));
    }

    /**
     * Проверяет, исключён ли обмен подсетями между двумя пирами VN
     * (прямое исключение или взаимное со стороны другого пира).
     */
    private function isPeerExcluded(AwgConfigPeer $membership, AwgConfigPeer $other): bool
    {
        $ownExcluded = is_array($membership->excluded_client_ids) ? $membership->excluded_client_ids : [];
        if (in_array($other->vpn_client_id, array_map('intval', $ownExcluded), true)) {
            return true;
        }

        $otherExcluded = is_array($other->excluded_client_ids) ? $other->excluded_client_ids : [];
        if ($other->exclusions_mutual && in_array($membership->vpn_client_id, array_map('intval', $otherExcluded), true)) {
            return true;
        }

        return false;
    }

    /**
     * Направление правила между membership и other:
     * - 'forward' — membership в src, other в dest (membership ходит к other);
     * - 'reply' — membership в dest, other в src (membership принимает трафик от other);
     * - null — правила нет.
     * Если есть оба направления (разные правила), предпочтение 'forward'
     * (подсети other важнее, чем только /32).
     */
    private function ruleDirection(AwgConfig $config, AwgConfigPeer $membership, AwgConfigPeer $other): ?string
    {
        $ownId = (int) $membership->vpn_client_id;
        $otherId = (int) $other->vpn_client_id;
        $forward = false;
        $reply = false;

        foreach ($config->vn_zones['rules'] ?? [] as $rule) {
            $src = array_map('intval', $rule['src_client_ids'] ?? []);
            $dest = array_map('intval', $rule['dest_client_ids'] ?? []);
            if (in_array($ownId, $src, true) && in_array($otherId, $dest, true)) {
                $forward = true;
            }
            if (in_array($ownId, $dest, true) && in_array($otherId, $src, true)) {
                $reply = true;
            }
        }

        if ($forward) {
            return 'forward';
        }
        if ($reply) {
            return 'reply';
        }

        return null;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, AwgConfigPeer> */
    private function enabledPeersForConfig(AwgConfig $config): \Illuminate\Database\Eloquent\Collection
    {
        if (! isset($this->enabledPeersCache[$config->id])) {
            $this->enabledPeersCache[$config->id] = AwgConfigPeer::query()
                ->where('awg_config_id', $config->id)
                ->where('enabled', true)
                ->get();
        }

        return $this->enabledPeersCache[$config->id];
    }

    /** @return list<string> */
    public function clientAllowedIps(AwgConfig $config, AwgConfigPeer $membership): array
    {
        if ($config->type === 'virtual_network') {
            $denyAll = ($config->vn_policy ?? 'allow_all') === 'deny_all';
            $ips = [$membership->address];
            $others = $this->enabledPeersForConfig($config)
                ->where('id', '!=', $membership->id);

            foreach ($others as $other) {
                if ($denyAll) {
                    $direction = $this->ruleDirection($config, $membership, $other);
                    if ($direction === 'forward') {
                        // источник → назначение: маршруты к подсетям назначения
                        $extras = $other->extra_allowed_ips ?? [];
                        if (is_array($extras)) {
                            foreach ($extras as $cidr) {
                                $cidr = trim((string) $cidr);
                                if ($cidr !== '') {
                                    $ips[] = $cidr;
                                }
                            }
                        }
                    } elseif ($direction === 'reply') {
                        // назначение ← источник: только туннельный /32 источника
                        // (для ответного трафика при masquerade на источнике)
                        if ($other->address) {
                            $ips[] = $other->address;
                        }
                    }
                    continue;
                }

                if ($this->isPeerExcluded($membership, $other)) {
                    continue;
                }
                $extras = $other->extra_allowed_ips ?? [];
                if (! is_array($extras)) {
                    continue;
                }
                foreach ($extras as $cidr) {
                    $cidr = trim((string) $cidr);
                    if ($cidr !== '') {
                        $ips[] = $cidr;
                    }
                }
            }

            return array_values(array_unique($ips));
        }

        if ($config->isResolverEnabled()) {
            // Full tunnel to VDS — non-list MASQUERADE → VDS IP.
            // List domains use FakeIP → sing-box → user VPN. Never put list CIDRs here.
            return ['0.0.0.0/0', '::/0'];
        }

        $raw = $config->client_allowed_ips ?: '0.0.0.0/0, ::/0';

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function clientAllowedIpsString(AwgConfig $config, AwgConfigPeer $membership): string
    {
        $key = $config->id.':'.$membership->id;
        if (! isset($this->clientAllowedIpsStringCache[$key])) {
            $this->clientAllowedIpsStringCache[$key] = implode(', ', $this->clientAllowedIps($config, $membership));
        }

        return $this->clientAllowedIpsStringCache[$key];
    }

    /** @return array<string, string> */
    public function generateJunkParams(): array
    {
        $jc = (string) random_int(1, 10);
        $jmin = random_int(64, 1023);
        $jmax = (string) random_int($jmin + 1, 1024);
        $jmin = (string) $jmin;

        $s1 = (string) random_int(0, 64);
        do {
            $s2 = (string) random_int(0, 64);
        } while ((int) $s1 + 56 === (int) $s2);
        $s3 = (string) random_int(0, 64);
        $s4 = (string) random_int(0, 32);

        $hs = [];
        while (count($hs) < 4) {
            $h = (string) random_int(1, 2147483647);
            if (! in_array($h, $hs, true)) {
                $hs[] = $h;
            }
        }

        return [
            'jc' => $jc,
            'jmin' => $jmin,
            'jmax' => $jmax,
            's1' => $s1,
            's2' => $s2,
            's3' => $s3,
            's4' => $s4,
            'h1' => $hs[0],
            'h2' => $hs[1],
            'h3' => $hs[2],
            'h4' => $hs[3],
            'i1' => '',
            'i2' => '',
            'i3' => '',
            'i4' => '',
            'i5' => '',
        ];
    }

    public function needsObfuscationParams(AwgConfig $config): bool
    {
        foreach (['jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4', 'h1', 'h2', 'h3', 'h4'] as $field) {
            if (trim((string) $config->{$field}) === '') {
                return true;
            }
        }

        return $config->jc === '4'
            && $config->jmin === '64'
            && $config->jmax === '80'
            && $config->s1 === '0'
            && $config->s2 === '0'
            && $config->s3 === '0'
            && $config->s4 === '0'
            && $config->h1 === '1'
            && $config->h2 === '2'
            && $config->h3 === '3'
            && $config->h4 === '4';
    }

    public function applyObfuscationParams(AwgConfig $config): bool
    {
        if (! $this->needsObfuscationParams($config)) {
            return false;
        }

        $config->fill($this->generateJunkParams());
        $config->save();

        return true;
    }

    public function needsServerKeys(AwgConfig $config): bool
    {
        return trim((string) $config->server_private_key) === ''
            || trim((string) $config->server_public_key) === '';
    }

    public function ensureServerKeys(AwgConfig $config): bool
    {
        if (! $this->needsServerKeys($config)) {
            return false;
        }

        $keys = $this->generateKeyPair();
        $config->server_private_key = $keys['private'];
        $config->server_public_key = $keys['public'];
        $config->save();

        return true;
    }

    public function needsPeerKeys(AwgConfigPeer $membership): bool
    {
        return trim((string) $membership->private_key) === ''
            || trim((string) $membership->public_key) === '';
    }

    public function ensurePeerKeys(AwgConfigPeer $membership): bool
    {
        if (! $this->needsPeerKeys($membership)) {
            return false;
        }

        $keys = $this->generateKeyPair();
        $membership->private_key = $keys['private'];
        $membership->public_key = $keys['public'];
        if (! $membership->preshared_key) {
            $membership->preshared_key = $this->generatePresharedKey();
        }
        $membership->save();

        return true;
    }

    /**
     * Ensure missing settings and a default AWG config exist in the database only.
     *
     * @return bool True when something was created for the first time.
     */
    public function ensureDbDefaults(): bool
    {
        $provisioned = false;

        foreach ($this->defaultSettings() as $key => $value) {
            if (Setting::getValue($key) === null) {
                Setting::setValue($key, $value);
                $provisioned = true;
            }
        }

        if (! AwgConfig::query()->exists()) {
            $keys = $this->generateKeyPair();
            $junk = $this->generateJunkParams();
            $attrs = array_merge($this->defaultConfigAttributes(), [
                'name' => 'Default',
                'iface' => 'awg0',
                'listen_port' => (int) env('AWG_PORT', 51820),
                'server_private_key' => $keys['private'],
                'server_public_key' => $keys['public'],
            ], $junk);

            AwgConfig::query()->create($attrs);
            $provisioned = true;
        } else {
            foreach (AwgConfig::query()->get() as $config) {
                if ($this->applyObfuscationParams($config)) {
                    $provisioned = true;
                }
                if ($this->ensureServerKeys($config)) {
                    $provisioned = true;
                }
                foreach (AwgConfigPeer::query()->where('awg_config_id', $config->id)->get() as $membership) {
                    if ($this->ensurePeerKeys($membership)) {
                        $provisioned = true;
                    }
                }
            }
        }

        return $provisioned;
    }

    /** Sync webhook config and AWG/resolver runtime files from the database. */
    public function bootstrapRuntime(): void
    {
        $this->writeWebhookConf();
        app(SslCertificateService::class)->ensureHttpCaddyfile();
        $this->applyConfig();
    }

    public function syncServerAddressFromSubnet(AwgConfig $config): void
    {
        if (preg_match('#^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$#', $config->internal_subnet, $m)) {
            $config->server_address = $m[1].'.1/'.$m[3];
            $config->save();
        }
    }

    public function nextClientAddress(AwgConfig $config): string
    {
        if (! preg_match('#^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$#', $config->internal_subnet, $m)) {
            throw new RuntimeException(__('configs.invalid_internal_subnet'));
        }
        $prefix = $m[1];
        $used = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->pluck('address')
            ->map(function ($addr) {
                if (preg_match('#\.(\d+)/#', $addr, $mm)) {
                    return (int) $mm[1];
                }

                return 0;
            })
            ->filter()
            ->all();

        for ($i = 2; $i < 254; $i++) {
            if (! in_array($i, $used, true)) {
                return "{$prefix}.{$i}/32";
            }
        }

        throw new RuntimeException(__('configs.no_free_addresses'));
    }

    /** @return array{iface:string,listen_port:int} */
    public function allocateIfaceAndPort(): array
    {
        return [
            'iface' => $this->allocateIface(),
            'listen_port' => $this->nextFreeListenPort(),
        ];
    }

    public function allocateIface(): string
    {
        $usedIfaces = AwgConfig::query()->pluck('iface')->all();

        for ($i = 0; $i <= self::PORT_MAX - self::PORT_MIN; $i++) {
            $iface = 'awg'.$i;
            if (! in_array($iface, $usedIfaces, true)) {
                return $iface;
            }
        }

        throw new RuntimeException(__('configs.config_limit_reached', ['count' => self::PORT_MAX - self::PORT_MIN + 1]));
    }

    public function nextFreeListenPort(): int
    {
        $usedPorts = AwgConfig::query()->pluck('listen_port')->map(fn ($p) => (int) $p)->all();

        for ($port = self::PORT_MIN; $port <= self::PORT_MAX; $port++) {
            if (! in_array($port, $usedPorts, true)) {
                return $port;
            }
        }

        throw new RuntimeException(__('configs.config_limit_reached', ['count' => self::PORT_MAX - self::PORT_MIN + 1]));
    }

    public function buildServerConfig(AwgConfig $config): string
    {
        if ($this->ensureServerKeys($config)) {
            $config->refresh();
        }

        $lines = [
            '[Interface]',
            'PrivateKey = '.$config->server_private_key,
            'Address = '.$config->server_address,
            'ListenPort = '.$config->listen_port,
            'Jc = '.$config->jc,
            'Jmin = '.$config->jmin,
            'Jmax = '.$config->jmax,
            'S1 = '.$config->s1,
            'S2 = '.$config->s2,
            'S3 = '.$config->s3,
            'S4 = '.$config->s4,
            'H1 = '.$config->h1,
            'H2 = '.$config->h2,
            'H3 = '.$config->h3,
            'H4 = '.$config->h4,
        ];

        foreach (['i1', 'i2', 'i3', 'i4', 'i5'] as $ikey) {
            $val = trim((string) ($config->{$ikey} ?? ''));
            if ($val !== '') {
                $lines[] = strtoupper($ikey).' = '.$val;
            }
        }

        $lines[] = 'PostUp = '.$this->buildPostUp($config);
        $lines[] = 'PostDown = '.$this->buildPostDown($config);
        $lines[] = '';

        $memberships = AwgConfigPeer::query()
            ->where('awg_config_id', $config->id)
            ->where('enabled', true)
            ->with('client')
            ->orderBy('id')
            ->get();

        foreach ($memberships as $membership) {
            if ($this->ensurePeerKeys($membership)) {
                $membership->refresh();
            }
            $lines[] = '[Peer]';
            $lines[] = '# '.($membership->client?->name ?? 'peer');
            $lines[] = 'PublicKey = '.$membership->public_key;
            if ($membership->preshared_key) {
                $lines[] = 'PresharedKey = '.$membership->preshared_key;
            }
            $lines[] = 'AllowedIPs = '.$this->serverPeerAllowedIpsString($membership);
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    public function buildClientConfig(AwgConfigPeer $membership): string
    {
        $membership->loadMissing(['config', 'client']);
        $config = $membership->config;
        if (! $config) {
            throw new RuntimeException('Config not found for membership');
        }

        if ($this->ensurePeerKeys($membership)) {
            $membership->refresh();
        }
        if ($this->ensureServerKeys($config)) {
            $config->refresh();
        }

        $endpointHost = $this->resolveEndpointHost();
        $dns = $config->isResolverEnabled()
            ? app(ResolverService::class)->gatewayIp($config)
            : ($config->peer_dns ?: '1.1.1.1');
        $allowed = $this->clientAllowedIpsString($config, $membership);
        $keepalive = $membership->keepalive ?? $config->persistent_keepalive ?? 25;

        $lines = [
            '[Interface]',
            'PrivateKey = '.$membership->private_key,
            'Jc = '.$config->jc,
            'Jmin = '.$config->jmin,
            'Jmax = '.$config->jmax,
            'S1 = '.$config->s1,
            'S2 = '.$config->s2,
            'S3 = '.$config->s3,
            'S4 = '.$config->s4,
            'H1 = '.$config->h1,
            'H2 = '.$config->h2,
            'H3 = '.$config->h3,
            'H4 = '.$config->h4,
        ];

        foreach (['i1', 'i2', 'i3', 'i4', 'i5'] as $ikey) {
            $val = trim((string) ($config->{$ikey} ?? ''));
            if ($val !== '') {
                $lines[] = strtoupper($ikey).' = '.$val;
            }
        }

        $lines[] = 'Address = '.$membership->address;
        $lines[] = 'DNS = '.$dns;
        $lines[] = '';
        $lines[] = '[Peer]';
        $lines[] = 'PublicKey = '.$config->server_public_key;
        if ($membership->preshared_key) {
            $lines[] = 'PresharedKey = '.$membership->preshared_key;
        }
        $lines[] = 'AllowedIPs = '.$allowed;
        $lines[] = "Endpoint = {$endpointHost}:{$config->listen_port}";
        $lines[] = 'PersistentKeepalive = '.$keepalive;

        return implode("\n", $lines)."\n";
    }

    public function applyConfig(?AwgConfig $only = null, bool $withResolver = true, bool $refreshSubscriptions = true): void
    {
        $dir = $this->configDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($only !== null) {
            if ($only->enabled) {
                $path = $this->configPath($only);
                file_put_contents($path, $this->buildServerConfig($only));
                @touch($path);
            }
        } else {
            $activeIfaces = [];
            foreach (AwgConfig::query()->where('enabled', true)->orderBy('id')->get() as $config) {
                $path = $this->configPath($config);
                file_put_contents($path, $this->buildServerConfig($config));
                @touch($path);
                $activeIfaces[] = $config->iface;
            }

            foreach (glob($dir.'/awg*.conf') ?: [] as $path) {
                $iface = basename($path, '.conf');
                if (! in_array($iface, $activeIfaces, true)) {
                    @unlink($path);
                }
            }
        }

        if (! $withResolver) {
            return;
        }

        try {
            app(ResolverService::class)->apply($refreshSubscriptions);
        } catch (\Throwable $e) {
            Log::warning('resolver apply after awg config: '.$e->getMessage());
        }
    }

    private function legacyResolverIptablesCleanup(): array
    {
        $dnsPort = ResolverService::DNS_REDIRECT_PORT;
        $tproxy = ResolverService::TPROXY_PORT;
        $fakeip = ResolverService::FAKEIP_CIDR;

        return [
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p tcp -j TPROXY --on-port {$tproxy} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p udp -j TPROXY --on-port {$tproxy} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p tcp -j TPROXY --on-port {$tproxy} --on-ip 0.0.0.0 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p udp -j TPROXY --on-port {$tproxy} --on-ip 0.0.0.0 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p tcp -j TPROXY --on-port {$tproxy} --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p udp -j TPROXY --on-port {$tproxy} --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -p udp --dport 53 -j TPROXY --on-port {$dnsPort} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -p tcp --dport 53 -j TPROXY --on-port {$dnsPort} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            'iptables -t mangle -D PREROUTING -p tcp -m socket -j DIVERT 2>/dev/null || true',
            'iptables -t mangle -D PREROUTING -p udp -m socket -j DIVERT 2>/dev/null || true',
            'iptables -t mangle -F DIVERT 2>/dev/null || true',
            'iptables -t mangle -X DIVERT 2>/dev/null || true',
            'iptables -t nat -D PREROUTING -i %i -d '.$fakeip.' -p tcp -j REDIRECT --to-ports '.$tproxy.' 2>/dev/null || true',
        ];
    }

    private function buildPostUp(AwgConfig $config): string
    {
        $parts = [
            'iptables -A FORWARD -i %i -j ACCEPT',
            'iptables -A FORWARD -o %i -j ACCEPT',
            'iptables -t nat -A POSTROUTING -o eth+ -j MASQUERADE',
        ];

        if ($config->isResolverEnabled()) {
            // Force ALL DNS from VPN clients into sing-box :53 (Amnezia often ignores tunnel DNS=)
            $parts[] = 'iptables -t nat -A PREROUTING -i %i -p udp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT;
            $parts[] = 'iptables -t nat -A PREROUTING -i %i -p tcp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT;

            // FakeIP → MARK → sing-box TUN; rest of full-tunnel traffic MASQUERADE on eth0
            app(ResolverService::class)->ensureResolverMarkScripts();
            $parts[] = 'sh /config/resolver-mark.sh %i';
            $parts[] = 'iptables -A FORWARD -i %i -o '.ResolverService::TUN_IFACE.' -j ACCEPT 2>/dev/null || true';
            $parts[] = 'iptables -A FORWARD -i '.ResolverService::TUN_IFACE.' -o %i -j ACCEPT 2>/dev/null || true';
            $parts = array_merge($parts, $this->legacyResolverIptablesCleanup());
        }

        return implode('; ', $parts);
    }

    private function buildPostDown(AwgConfig $config): string
    {
        $parts = [
            'iptables -D FORWARD -i %i -j ACCEPT',
            'iptables -D FORWARD -o %i -j ACCEPT',
            'iptables -t nat -D POSTROUTING -o eth+ -j MASQUERADE',
        ];

        if ($config->isResolverEnabled()) {
            $parts[] = 'iptables -t nat -D PREROUTING -i %i -p udp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT.' 2>/dev/null || true';
            $parts[] = 'iptables -t nat -D PREROUTING -i %i -p tcp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT.' 2>/dev/null || true';
            $parts[] = 'sh /config/resolver-unmark.sh %i 2>/dev/null || true';
            $parts[] = 'iptables -D FORWARD -i %i -o '.ResolverService::TUN_IFACE.' -j ACCEPT 2>/dev/null || true';
            $parts[] = 'iptables -D FORWARD -i '.ResolverService::TUN_IFACE.' -o %i -j ACCEPT 2>/dev/null || true';
            $parts = array_merge($parts, $this->legacyResolverIptablesCleanup());
        }

        return implode('; ', $parts);
    }

    public function writeWebhookConf(): void
    {
        $dir = $this->hostGuiDir();
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $url = Setting::getValue('failure_webhook_url', '');
        $panelPort = Setting::getValue('panel_port', env('PANEL_PORT', '8877'));
        $panelHttpsPort = $this->resolvePanelHttpsPort();
        $endpoint = Setting::getValue('server_endpoint', 'auto');
        $panelDomain = $this->resolvePanelDomain();
        $timezone = $this->resolveTimezone();
        $sslEnabled = filter_var(Setting::getValue('ssl_enabled', '0'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $content = "WEBHOOK_URL={$url}\nPANEL_PORT={$panelPort}\nPANEL_HTTPS_PORT={$panelHttpsPort}\nSERVER_ENDPOINT={$endpoint}\nPANEL_DOMAIN={$panelDomain}\nSSL_ENABLED={$sslEnabled}\nTZ={$timezone}\n";
        @file_put_contents($dir.'/webhook.conf', $content);
    }

    /** @return array<string, mixed>|null */
    private function parseDumpLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $parts = explode("\t", $line);
        if (count($parts) >= 8) {
            return [
                'public_key' => $parts[0],
                'endpoint' => $parts[2] === '(none)' ? null : $parts[2],
                'allowed_ips' => $parts[3],
                'latest_handshake' => (int) $parts[4],
                'transfer_rx' => (int) $parts[5],
                'transfer_tx' => (int) $parts[6],
                'persistent_keepalive' => $parts[7],
            ];
        }

        $parts = preg_split('/\s+/', $line);
        if (! $parts || count($parts) < 8) {
            return null;
        }

        $n = count($parts);

        return [
            'public_key' => $parts[0],
            'endpoint' => $parts[$n - 6] === '(none)' ? null : $parts[$n - 6],
            'allowed_ips' => implode(' ', array_slice($parts, 2, $n - 8)),
            'latest_handshake' => (int) $parts[$n - 5],
            'transfer_rx' => (int) $parts[$n - 4],
            'transfer_tx' => (int) $parts[$n - 3],
            'persistent_keepalive' => $parts[$n - 2],
        ];
    }

    /** @return array{available:bool,by_pub:array<string, array<string, mixed>>} */
    private function dumpStatsForIface(string $iface): array
    {
        $byPub = [];
        $result = $this->docker->exec(
            $this->containerName(),
            ['awg', 'show', $iface, 'dump'],
        );

        if (! $result->successful()) {
            Log::warning('awg stats dump failed', [
                'iface' => $iface,
                'stderr' => trim($result->errorOutput()),
            ]);

            return ['available' => false, 'by_pub' => []];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($result->output())) ?: [];
        foreach (array_slice($lines, 1) as $line) {
            $parsed = $this->parseDumpLine($line);
            if ($parsed) {
                $byPub[$parsed['public_key']] = $parsed;
            }
        }

        return ['available' => true, 'by_pub' => $byPub];
    }

    /**
     * @return array{stats_available:bool,peers:array<int, array<string, mixed>>}
     */
    public function peerStats(?int $configId = null): array
    {
        $configs = AwgConfig::query()
            ->when($configId, fn ($q) => $q->where('id', $configId))
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        $statsAvailable = true;
        $byPubIface = [];

        foreach ($configs as $config) {
            $dump = $this->dumpStatsForIface($config->iface);
            if (! $dump['available']) {
                $statsAvailable = false;
            }
            foreach ($dump['by_pub'] as $pub => $stat) {
                $byPubIface[$config->id][$pub] = $stat;
            }
        }

        $memberships = AwgConfigPeer::query()
            ->with(['client', 'config'])
            ->when($configId, fn ($q) => $q->where('awg_config_id', $configId))
            ->orderBy('id')
            ->get();

        foreach ($configs as $config) {
            $this->enabledPeersForConfig($config);
        }

        $out = [];
        foreach ($memberships as $membership) {
            $config = $membership->config;
            if (! $config) {
                continue;
            }

            $stat = $byPubIface[$config->id][$membership->public_key] ?? null;
            $handshake = $stat['latest_handshake'] ?? 0;
            $online = $handshake > 0 && (time() - $handshake) < 180;

            $out[] = [
                'membership_id' => $membership->id,
                'config_id' => $config->id,
                'config_name' => $config->name,
                'config_iface' => $config->iface,
                'config_type' => $config->type,
                'id' => $membership->vpn_client_id,
                'client_id' => $membership->vpn_client_id,
                'name' => $membership->client?->name,
                'enabled' => $membership->enabled,
                'address' => $membership->address,
                'extra_allowed_ips' => array_values($membership->extra_allowed_ips ?? []),
                'excluded_client_ids' => array_values(array_map('intval', $membership->excluded_client_ids ?? [])),
                'exclusions_mutual' => (bool) $membership->exclusions_mutual,
                'server_allowed_ips' => $this->serverPeerAllowedIpsString($membership),
                'client_allowed_ips' => $this->clientAllowedIpsString($config, $membership),
                'public_key' => $membership->public_key,
                'endpoint' => $stat['endpoint'] ?? null,
                'latest_handshake' => $handshake ?: null,
                'latest_handshake_human' => $handshake ? date('c', $handshake) : null,
                'transfer_rx' => $stat['transfer_rx'] ?? 0,
                'transfer_tx' => $stat['transfer_tx'] ?? 0,
                'online' => $online,
            ];
        }

        return [
            'stats_available' => $statsAvailable,
            'peers' => $out,
        ];
    }

    /**
     * Только live-статистика AWG (docker exec), без пересчёта allowed_ips и links.
     *
     * @param  list<int>|int|null  $configIds  null = all enabled configs
     * @return array{stats_available:bool,by_public_key:array<string, array<string, mixed>>}
     */
    public function livePeerStats(int|array|null $configIds = null): array
    {
        $ids = null;
        if (is_int($configIds)) {
            $ids = $configIds > 0 ? [$configIds] : [];
        } elseif (is_array($configIds)) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $configIds),
                fn (int $id) => $id > 0
            )));
        }

        $configs = AwgConfig::query()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        $statsAvailable = true;
        $byPublicKey = [];

        foreach ($configs as $config) {
            $dump = $this->dumpStatsForIface($config->iface);
            if (! $dump['available']) {
                $statsAvailable = false;
            }

            foreach ($dump['by_pub'] as $pub => $stat) {
                $handshake = $stat['latest_handshake'] ?? 0;
                $online = $handshake > 0 && (time() - $handshake) < 180;

                $byPublicKey[$pub] = [
                    'endpoint' => $stat['endpoint'] ?? null,
                    'latest_handshake' => $handshake ?: null,
                    'latest_handshake_human' => $handshake ? date('c', $handshake) : null,
                    'transfer_rx' => $stat['transfer_rx'] ?? 0,
                    'transfer_tx' => $stat['transfer_tx'] ?? 0,
                    'online' => $online,
                ];
            }
        }

        return [
            'stats_available' => $statsAvailable,
            'by_public_key' => $byPublicKey,
        ];
    }

    /**
     * Направленные связи пир—пир для virtual_network.
     * Стрелка from→to означает, что у from в клиентском AllowedIPs есть маршруты к to
     * (подсеть при forward / политика allow_all, либо правило src→dest).
     * bidirectional=true — маршруты есть в обе стороны (стрелки на обоих концах).
     *
     * @return list<array{config_id:int,from_membership_id:int,to_membership_id:int,bidirectional:bool}>
     */
    public function peerLinks(?int $configId = null): array
    {
        $configs = AwgConfig::query()
            ->when($configId, fn ($q) => $q->where('id', $configId))
            ->where('enabled', true)
            ->where('type', 'virtual_network')
            ->orderBy('id')
            ->get();

        $links = [];
        foreach ($configs as $config) {
            $denyAll = ($config->vn_policy ?? 'allow_all') === 'deny_all';
            $peers = $this->enabledPeersForConfig($config)->sortBy('id')->values();

            for ($i = 0; $i < $peers->count(); $i++) {
                for ($j = $i + 1; $j < $peers->count(); $j++) {
                    $a = $peers[$i];
                    $b = $peers[$j];

                    if ($denyAll) {
                        $ab = $this->ruleDirection($config, $a, $b) === 'forward';
                        $ba = $this->ruleDirection($config, $b, $a) === 'forward';
                    } else {
                        // allow_all: у пира есть маршруты к другому, если тот не исключён
                        $ab = ! $this->isPeerExcluded($a, $b);
                        $ba = ! $this->isPeerExcluded($b, $a);
                    }

                    if ($ab && $ba) {
                        $links[] = [
                            'config_id' => (int) $config->id,
                            'from_membership_id' => (int) $a->id,
                            'to_membership_id' => (int) $b->id,
                            'bidirectional' => true,
                        ];
                    } elseif ($ab) {
                        $links[] = [
                            'config_id' => (int) $config->id,
                            'from_membership_id' => (int) $a->id,
                            'to_membership_id' => (int) $b->id,
                            'bidirectional' => false,
                        ];
                    } elseif ($ba) {
                        $links[] = [
                            'config_id' => (int) $config->id,
                            'from_membership_id' => (int) $b->id,
                            'to_membership_id' => (int) $a->id,
                            'bidirectional' => false,
                        ];
                    }
                }
            }
        }

        return $links;
    }

    public function resolvePanelUrl(): string
    {
        $sslEnabled = filter_var(Setting::getValue('ssl_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        $domain = $this->resolvePanelDomain();
        if ($sslEnabled && $domain !== '') {
            $httpsPort = (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));

            return 'https://'.$domain.':'.$httpsPort;
        }

        $port = Setting::getValue('panel_port', env('PANEL_PORT', '8877'));

        return 'http://'.$this->resolvePanelHost().':'.$port;
    }

    public function resolvePanelHttpsPort(): string
    {
        return (string) Setting::getValue('panel_https_port', env('PANEL_HTTPS_PORT', '7443'));
    }

    /**
     * Best-effort sync of panel ports / APP_URL into the host compose .env.
     *
     * @param  array<string, string>  $extra
     */
    public function syncPanelUrlToHostEnv(array $extra = []): void
    {
        $httpPort = (string) Setting::getValue('panel_port', env('PANEL_PORT', '8877'));
        $httpsPort = $this->resolvePanelHttpsPort();
        $appUrl = $this->resolvePanelUrl();

        $values = array_merge([
            'PANEL_PORT' => $httpPort,
            'PANEL_HTTPS_PORT' => $httpsPort,
            'APP_URL' => $appUrl,
        ], $extra);

        $candidates = [];
        $conf = $this->hostGuiDir().'/awg-gui.conf';
        if (is_readable($conf)) {
            foreach (file($conf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with($line, 'ENV_FILE=')) {
                    $candidates[] = substr($line, strlen('ENV_FILE='));
                }
            }
        }
        $candidates[] = rtrim((string) env('HOST_COMPOSE_DIR', '/compose'), '/').'/.env';
        $candidates[] = base_path('../.env');

        foreach (array_unique(array_filter($candidates)) as $path) {
            if (! is_writable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            foreach ($values as $key => $value) {
                if (preg_match('/^'.preg_quote($key, '/').'=.*/m', $raw)) {
                    $raw = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $key.'='.$value, $raw, 1);
                } else {
                    $raw = rtrim($raw)."\n{$key}={$value}\n";
                }
            }
            @file_put_contents($path, $raw);
            break;
        }
    }

    /**
     * Validate panel HTTP/HTTPS TCP ports.
     *
     * @throws \InvalidArgumentException
     */
    public function assertPanelPorts(string $httpPort, string $httpsPort): void
    {
        foreach (['HTTP' => $httpPort, 'HTTPS' => $httpsPort] as $label => $port) {
            if (! ctype_digit((string) $port)) {
                throw new \InvalidArgumentException(__('settings.port_must_be_number', ['label' => $label]));
            }
            $n = (int) $port;
            if ($n < 1 || $n > 65535) {
                throw new \InvalidArgumentException(__('settings.port_out_of_range', ['label' => $label]));
            }
        }

        if ((int) $httpPort === (int) $httpsPort) {
            throw new \InvalidArgumentException(__('settings.http_https_ports_must_differ'));
        }
    }

    /** @return array{server_endpoint: string, display_endpoint: string, awg_port: int, listen_port: int|null, endpoint: string} */
    public function endpointStatus(): array
    {
        $this->ensureDbDefaults();

        $stored = (string) Setting::getValue('server_endpoint', env('SERVER_ENDPOINT', 'auto'));
        $display = $this->resolveEndpointHost();
        $awgPort = (int) env('AWG_PORT', self::PORT_MIN);
        $config = AwgConfig::query()->orderBy('id')->first();
        $listenPort = $config ? (int) $config->listen_port : null;
        $port = $listenPort ?? $awgPort;

        return [
            'server_endpoint' => $stored,
            'display_endpoint' => $display,
            'awg_port' => $awgPort,
            'listen_port' => $listenPort,
            'endpoint' => "{$display}:{$port}",
        ];
    }

    /**
     * @return array{server_endpoint: string, display_endpoint: string, awg_port: int, listen_port: int|null, endpoint: string, restarted: bool}
     */
    public function updateServerEndpoint(?string $endpoint = null, ?int $port = null, bool $restart = true): array
    {
        $this->ensureDbDefaults();

        if ($endpoint !== null) {
            $endpoint = trim($endpoint);
            if ($endpoint === '') {
                throw new RuntimeException('Endpoint cannot be empty');
            }
            if ($endpoint !== 'auto' && ! $this->isValidEndpointHost($endpoint)) {
                throw new RuntimeException('Invalid endpoint: use an IP, hostname, or "auto"');
            }
            Setting::setValue('server_endpoint', $endpoint);
        }

        $portChanged = false;
        if ($port !== null) {
            if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
                throw new RuntimeException('Port must be between '.self::PORT_MIN.' and '.self::PORT_MAX);
            }

            $config = AwgConfig::query()->orderBy('id')->first();
            if (! $config) {
                throw new RuntimeException('No AWG config found');
            }

            $conflict = AwgConfig::query()
                ->where('listen_port', $port)
                ->where('id', '!=', $config->id)
                ->exists();
            if ($conflict) {
                throw new RuntimeException("Port {$port} is already used by another config");
            }

            if ((int) $config->listen_port !== $port) {
                $config->listen_port = $port;
                $config->save();
                $portChanged = true;
            }
        }

        $this->writeWebhookConf();

        $restarted = false;
        if ($portChanged) {
            $this->applyConfig();
            if ($restart) {
                $result = $this->restartAwg();
                $restarted = (bool) ($result['ok'] ?? false);
                if (! $restarted) {
                    throw new RuntimeException('Failed to restart AmneziaWG container');
                }
            }
        }

        $status = $this->endpointStatus();
        $status['restarted'] = $restarted;

        return $status;
    }

    private function isValidEndpointHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $host);
    }

    public function isAwgRestarting(): bool
    {
        return Cache::has(self::RESTART_LOCK_KEY);
    }

    public function restartAwg(): array
    {
        if (! Cache::add(self::RESTART_LOCK_KEY, time(), self::RESTART_LOCK_TTL)) {
            return [
                'ok' => false,
                'already_restarting' => true,
                'exit_code' => null,
                'stderr' => '',
            ];
        }

        try {
            $this->applyConfig();

            $result = $this->docker->restart($this->containerName(), timeout: 60);

            return [
                'ok' => $result->successful(),
                'exit_code' => $result->exitCode(),
                'stderr' => trim($result->errorOutput()),
            ];
        } finally {
            Cache::forget(self::RESTART_LOCK_KEY);
        }
    }

    public function regenerateConfigKeys(AwgConfig $config): array
    {
        $keys = $this->generateKeyPair();
        $config->server_private_key = $keys['private'];
        $config->server_public_key = $keys['public'];
        $config->save();
        $this->applyConfig();

        return ['server_public_key' => $keys['public']];
    }

    /** @return array<string, string> */
    public function regenerateConfigJunk(AwgConfig $config): array
    {
        $junk = $this->generateJunkParams();
        $config->fill($junk);
        $config->save();
        $this->applyConfig();

        return $junk;
    }
}
