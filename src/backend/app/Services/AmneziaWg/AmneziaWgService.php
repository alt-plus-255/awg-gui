<?php

namespace App\Services\AmneziaWg;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\Setting;
use App\Models\VpnClient;
use App\Services\Resolver\ResolverService;
use App\Services\Docker\DockerRuntime;
use App\Services\AmneziaWg\Versions\AwgVersionProfile;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AmneziaWgService
{
    /** @var array<int, \Illuminate\Database\Eloquent\Collection<int, AwgConfigPeer>> */
    private array $enabledPeersCache = [];

    /** @var array<string, string> */
    private array $clientAllowedIpsStringCache = [];

    public function __construct(
        private DockerRuntime $docker,
        private ?AwgVersionRegistry $versions = null,
    ) {}

    public function versions(): AwgVersionRegistry
    {
        return $this->versions ??= app(AwgVersionRegistry::class);
    }

    public function profileFor(AwgConfig|string|null $configOrVersion): AwgVersionProfile
    {
        if ($configOrVersion instanceof AwgConfig) {
            return $this->versions()->profileForConfig($configOrVersion->protocol_version);
        }

        return $this->versions()->profileForConfig($configOrVersion);
    }

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
            // Off by default: keep panel reachable by IP and by domain after SSL.
            'redirect_ip_to_domain' => '0',
            'panel_port' => (string) env('PANEL_PORT', '8877'),
            'panel_https_port' => (string) env('PANEL_HTTPS_PORT', '7443'),
            'ssl_email' => '',
            'ssl_enabled' => '0',
            'ssl_status' => 'disabled',
            'ssl_error' => '',
            'ssl_expires_at' => '',
            'ssl_pending_challenge' => '',
            'failure_webhook_url' => '',
            'timezone' => (string) env('TZ', 'UTC'),
            'telegram_bot_token' => '',
            'telegram_admin_id' => '',
            'telegram_mode' => 'polling',
            'telegram_proxies' => '[]',
            'telegram_proxy_strategy' => 'fastest',
            'telegram_notifications_enabled' => '1',
            'telegram_daily_report_enabled' => '1',
            'telegram_webhook_secret' => '',
            'telegram_mixed_auth_user' => '',
            'telegram_mixed_auth_pass' => '',
            // auto = detect default-route NIC inside AWG container; or explicit iface name.
            'singbox_egress_interface' => 'auto',
        ];
    }

    public const CLIENT_IMPORT_NAME_PEER = 'peer_name';

    public const CLIENT_IMPORT_NAME_VERSION_HOST = 'version_host';

    /** @return list<string> */
    public function clientImportNameStyles(): array
    {
        return [self::CLIENT_IMPORT_NAME_PEER, self::CLIENT_IMPORT_NAME_VERSION_HOST];
    }

    public function resolveClientImportNameStyle(?AwgConfig $config = null, ?string $style = null): string
    {
        if ($style !== null) {
            $style = trim($style);

            return in_array($style, $this->clientImportNameStyles(), true)
                ? $style
                : self::CLIENT_IMPORT_NAME_PEER;
        }

        if ($config !== null) {
            $style = trim((string) ($config->client_import_name_style ?? ''));

            return in_array($style, $this->clientImportNameStyles(), true)
                ? $style
                : self::CLIENT_IMPORT_NAME_PEER;
        }

        return self::CLIENT_IMPORT_NAME_PEER;
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
            'client_import_name_style' => self::CLIENT_IMPORT_NAME_PEER,
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
     * Detect the server's current public IPv4 (same sources as the host installer).
     */
    public function detectPublicIpv4(): string
    {
        foreach (['https://ifconfig.me', 'https://api.ipify.org'] as $url) {
            try {
                $response = Http::timeout(5)
                    ->withHeaders(['Accept' => 'text/plain'])
                    ->get($url);
                if (! $response->successful()) {
                    continue;
                }
                $ip = trim((string) $response->body());
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $ip;
                }
            } catch (\Throwable) {
                // try next source
            }
        }

        $host = trim((string) (request()?->getHost() ?: ''));
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        return '';
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

    /**
     * When SSL is on and this is true, Caddy sends IP (non-domain) hosts to https://domain.
     * Default false — panel stays reachable by IP and by domain.
     */
    public function shouldRedirectIpToDomain(): bool
    {
        if ($this->resolvePanelDomain() === '') {
            return false;
        }

        return filter_var(Setting::getValue('redirect_ip_to_domain', '0'), FILTER_VALIDATE_BOOLEAN);
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

    /**
     * Validate panel domain against the server's real public IPv4.
     * WireGuard SERVER_ENDPOINT may be a LAN address on NAT/home installs —
     * that must not be used for DNS matching.
     *
     * Intentionally does NOT probe http://{domain} (would be SSRF).
     *
     * @throws \InvalidArgumentException
     */
    public function assertPanelDomainDns(string $domain): void
    {
        $domain = trim($domain);
        if ($domain === '') {
            return;
        }

        $resolved = $this->resolveIpv4Addresses($domain);
        if ($resolved === []) {
            throw new \InvalidArgumentException(
                __('settings.domain_no_a_record', ['domain' => $domain])
            );
        }

        // Reject private/reserved targets — domain must point at a public address.
        foreach ($resolved as $ip) {
            if (! $this->isPublicIpv4($ip)) {
                throw new \InvalidArgumentException(
                    __('settings.domain_points_private', [
                        'domain' => $domain,
                        'got' => implode(', ', $resolved),
                    ])
                );
            }
        }

        $candidates = [];
        $detected = $this->detectPublicIpv4();
        if ($detected !== '' && $this->isPublicIpv4($detected)) {
            $candidates[] = $detected;
        }
        $endpoint = $this->resolveServerEndpointHost();
        if ($this->isPublicIpv4($endpoint)) {
            $candidates[] = $endpoint;
        }
        $candidates = array_values(array_unique($candidates));

        if ($candidates === []) {
            throw new \InvalidArgumentException(__('settings.public_ip_detect_failed'));
        }

        foreach ($candidates as $ip) {
            if (in_array($ip, $resolved, true)) {
                return;
            }
        }

        $got = implode(', ', $resolved);
        throw new \InvalidArgumentException(
            __('settings.domain_points_elsewhere', [
                'domain' => $domain,
                'got' => $got,
                'host' => $candidates[0],
            ])
        );
    }

    public function isPublicIpv4(string $ip): bool
    {
        $ip = trim($ip);

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
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

        // Server configs: extra_allowed_ips are client-side split-tunnel routes
        // (resources behind/near the server). Putting them on the server [Peer]
        // steals the route into awgN (cryptokey loop). VN keeps LAN-behind-peer.
        if (! $membership->relationLoaded('config') && $membership->awg_config_id) {
            $membership->loadMissing('config');
        }
        if ($membership->relationLoaded('config') && $membership->getRelation('config')?->type === 'server') {
            return array_values(array_unique(array_filter($ips)));
        }

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
            // Client → AWG full tunnel. On the VDS, non-list traffic MASQUERADE → VDS IP (direct).
            // List domains: FakeIP → selective TPROXY → sing-box → connection. Never put list CIDRs in AllowedIPs.
            return ['0.0.0.0/0', '::/0'];
        }

        // Server without resolver: peer extras → split-tunnel (tunnel subnet + CIDRs).
        // Use network-aligned CIDR (internal_subnet / canonicalized server_address).
        // Android WireGuard rejects host addresses like 10.66.66.1/24 → Error 1000.
        $extras = $membership->extra_allowed_ips ?? [];
        if (! is_array($extras)) {
            $extras = [];
        }
        $splitCidrs = [];
        foreach ($extras as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '' || $cidr === '0.0.0.0/0' || $cidr === '::/0') {
                continue;
            }
            $canonical = $this->canonicalNetworkCidr($cidr) ?? $cidr;
            if ($canonical === '0.0.0.0/0' || $canonical === '::/0') {
                continue;
            }
            $splitCidrs[] = $canonical;
        }
        $splitCidrs = array_values(array_unique($splitCidrs));
        if ($splitCidrs !== []) {
            $ips = [];
            $tunnel = trim((string) ($config->internal_subnet ?? ''));
            if ($tunnel === '') {
                $tunnel = trim((string) ($config->server_address ?? ''));
            }
            $tunnelCidr = $this->canonicalNetworkCidr($tunnel);
            if ($tunnelCidr !== null && $tunnelCidr !== '0.0.0.0/0' && $tunnelCidr !== '::/0') {
                $ips[] = $tunnelCidr;
            }
            foreach ($splitCidrs as $cidr) {
                if (! in_array($cidr, $ips, true)) {
                    $ips[] = $cidr;
                }
            }

            return array_values($ips);
        }

        $raw = $config->client_allowed_ips ?: '0.0.0.0/0, ::/0';

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Normalize a CIDR to network form (host bits cleared) for AllowedIPs.
     * Android WireGuard rejects non-canonical routes like 10.66.66.1/24.
     */
    public function canonicalNetworkCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);
        if ($cidr === '' || ! str_contains($cidr, '/')) {
            return null;
        }

        [$ip, $prefixRaw] = explode('/', $cidr, 2);
        $ip = trim($ip);
        if (! ctype_digit($prefixRaw)) {
            return null;
        }
        $prefix = (int) $prefixRaw;

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($prefix < 0 || $prefix > 32) {
                return null;
            }
            $ipLong = ip2long($ip);
            if ($ipLong === false) {
                return null;
            }
            $mask = $prefix === 0 ? 0 : (~((1 << (32 - $prefix)) - 1) & 0xFFFFFFFF);
            $network = long2ip($ipLong & $mask);
            if ($network === false) {
                return null;
            }

            return $network.'/'.$prefix;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($prefix < 0 || $prefix > 128) {
                return null;
            }
            $binary = inet_pton($ip);
            if ($binary === false) {
                return null;
            }
            $bits = unpack('C*', $binary);
            if (! is_array($bits)) {
                return null;
            }
            $bytes = array_values($bits);
            for ($i = 0; $i < 16; $i++) {
                $bitStart = $i * 8;
                if ($prefix >= $bitStart + 8) {
                    continue;
                }
                if ($prefix <= $bitStart) {
                    $bytes[$i] = 0;
                    continue;
                }
                $keep = $prefix - $bitStart;
                $bytes[$i] = $bytes[$i] & (0xFF << (8 - $keep) & 0xFF);
            }
            $packed = pack('C*', ...$bytes);
            $network = inet_ntop($packed);
            if ($network === false) {
                return null;
            }

            return $network.'/'.$prefix;
        }

        return null;
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
    public function generateJunkParams(?string $protocolVersion = null): array
    {
        return $this->profileFor($protocolVersion)->generateJunkParams();
    }

    public function needsObfuscationParams(AwgConfig $config): bool
    {
        return $this->profileFor($config)->needsObfuscationParams($config);
    }

    public function applyObfuscationParams(AwgConfig $config): bool
    {
        if (! $this->needsObfuscationParams($config)) {
            return false;
        }

        $config->fill($this->generateJunkParams($config->protocol_version));
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
            $version = $this->versions()->latest();
            $junk = $this->generateJunkParams($version);
            $attrs = array_merge($this->defaultConfigAttributes(), [
                'name' => 'Default',
                'iface' => 'awg0',
                'listen_port' => (int) env('AWG_PORT', 51820),
                'protocol_version' => $version,
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
        $ssl = app(SslCertificateService::class);
        // After package updates the installer may seed a default HTTP Caddyfile.
        // Re-apply the SSL site block when the panel still has SSL enabled so
        // Caddy picks it up on start (bind-mount) or after a best-effort reload.
        if ($ssl->isSslEnabled()) {
            $ssl->writeCaddyfile(true);
            try {
                $ssl->reloadOrRecreateCaddy();
            } catch (\Throwable) {
                // Caddy often is not up yet during app entrypoint; file is enough.
            }
        } else {
            $ssl->ensureHttpCaddyfile();
        }
        $this->syncPanelUrlToHostEnv();
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
        ];
        array_push($lines, ...$this->profileFor($config)->confObfuscationLines($config));

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

    /**
     * Display name for Amnezia / AmneziaWG when importing QR, vpn:// or .conf (# Name =).
     */
    public function clientImportLabel(AwgConfigPeer $membership, ?string $endpointHost = null, ?string $style = null): string
    {
        $membership->loadMissing(['config', 'client']);
        $config = $membership->config;
        if (! $config) {
            throw new RuntimeException('Config not found for membership');
        }

        $style = $this->resolveClientImportNameStyle($config, $style);

        if ($style === self::CLIENT_IMPORT_NAME_VERSION_HOST) {
            $version = trim((string) ($config->protocol_version ?: '2.0'));
            $host = trim($endpointHost ?? $this->resolveEndpointHost());
            if ($host === '') {
                $host = '127.0.0.1';
            }

            return 'AWG-v'.$version.'-'.$host;
        }

        $peerName = trim((string) ($membership->client?->name ?? ''));
        if ($peerName === '') {
            $peerName = 'peer';
        }

        return 'awg-'.$peerName;
    }

    public function clientImportFilename(AwgConfigPeer $membership, ?string $endpointHost = null, ?string $style = null): string
    {
        $base = $this->clientImportLabel($membership, $endpointHost, $style);
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $base) ?: 'awg-client';

        return $safe.'.conf';
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
        $importLabel = $this->clientImportLabel($membership, $endpointHost);

        // Field order matches awg-web-gui build_client_conf: Address/DNS before AWG params.
        $lines = [
            '# Name = '.$importLabel,
            '[Interface]',
            'PrivateKey = '.$membership->private_key,
            'Address = '.$membership->address,
            'DNS = '.$dns,
            'MTU = 1420',
        ];
        array_push($lines, ...$this->profileFor($config)->confObfuscationLines($config));

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
                // Only manage server ifaces (awg0, awg1, …). Client exit tunnels awgc{id} are owned by ResolverService.
                if (! preg_match('/^awg\d+$/', $iface)) {
                    continue;
                }
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

    /**
     * Drop obsolete pre-chain FakeIP rules from older resolver builds.
     * Do NOT touch DIVERT here — UDP FakeIP TPROXY needs FakeIP-scoped DIVERT from resolver-mark.sh.
     *
     * @return list<string>
     */
    private function legacyResolverIptablesCleanup(): array
    {
        $dnsPort = ResolverService::DNS_REDIRECT_PORT;
        $tproxy = ResolverService::TPROXY_PORT;
        $fakeip = ResolverService::FAKEIP_CIDR;

        return [
            // Flat FakeIP TPROXY (before RS_<iface> chain)
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p tcp -j TPROXY --on-port {$tproxy} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p udp -j TPROXY --on-port {$tproxy} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p tcp -j TPROXY --on-port {$tproxy} --on-ip 0.0.0.0 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p udp -j TPROXY --on-port {$tproxy} --on-ip 0.0.0.0 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p tcp -j TPROXY --on-port {$tproxy} --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -d {$fakeip} -p udp -j TPROXY --on-port {$tproxy} --tproxy-mark 0x1/0x1 2>/dev/null || true",
            // Old DNS TPROXY to 5353
            "iptables -t mangle -D PREROUTING -i %i -p udp --dport 53 -j TPROXY --on-port {$dnsPort} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            "iptables -t mangle -D PREROUTING -i %i -p tcp --dport 53 -j TPROXY --on-port {$dnsPort} --on-ip 127.0.0.1 --tproxy-mark 0x1/0x1 2>/dev/null || true",
            // Old NAT REDIRECT of FakeIP to tproxy port
            'iptables -t nat -D PREROUTING -i %i -d '.$fakeip.' -p tcp -j REDIRECT --to-ports '.$tproxy.' 2>/dev/null || true',
            // Legacy TUN forward leftovers
            'iptables -D FORWARD -i %i -o '.ResolverService::TUN_IFACE.' -j ACCEPT 2>/dev/null || true',
            'iptables -D FORWARD -i '.ResolverService::TUN_IFACE.' -o %i -j ACCEPT 2>/dev/null || true',
        ];
    }

    private function buildPostUp(AwgConfig $config): string
    {
        $egress = app(\App\Services\Resolver\EgressInterfaceResolver::class)->resolve();
        $parts = [
            'iptables -A FORWARD -i %i -j ACCEPT',
            'iptables -A FORWARD -o %i -j ACCEPT',
            'iptables -t nat -A POSTROUTING -o '.$egress.' -j MASQUERADE',
        ];

        if ($config->isResolverEnabled()) {
            // Resolver ON: AWG still owns the tunnel; DNS + selective FakeIP REDIRECT (+ optional UDP TPROXY) are side-cars.
            // Resolver OFF: PostUp is only FORWARD+MASQUERADE (all client traffic = direct / VDS IP).
            $parts[] = 'iptables -t nat -A PREROUTING -i %i -p udp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT;
            $parts[] = 'iptables -t nat -A PREROUTING -i %i -p tcp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT;

            // Strip obsolete flat rules first, then install current REDIRECT (+ UDP TPROXY or REJECT) path.
            $parts = array_merge($parts, $this->legacyResolverIptablesCleanup());
            app(ResolverService::class)->ensureResolverMarkScripts();
            $rejectQuic = $config->resolver_reject_quic ? '1' : '0';
            $parts[] = 'sh /config/resolver-mark.sh %i '.$rejectQuic;
        }

        return implode('; ', $parts);
    }

    private function buildPostDown(AwgConfig $config): string
    {
        $egress = app(\App\Services\Resolver\EgressInterfaceResolver::class)->resolve();
        $parts = [
            'iptables -D FORWARD -i %i -j ACCEPT',
            'iptables -D FORWARD -o %i -j ACCEPT',
            'iptables -t nat -D POSTROUTING -o '.$egress.' -j MASQUERADE',
            // Legacy wildcard MASQUERADE from older builds.
            'iptables -t nat -D POSTROUTING -o eth+ -j MASQUERADE 2>/dev/null || true',
        ];

        if ($config->isResolverEnabled()) {
            $parts[] = 'iptables -t nat -D PREROUTING -i %i -p udp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT.' 2>/dev/null || true';
            $parts[] = 'iptables -t nat -D PREROUTING -i %i -p tcp --dport 53 -j REDIRECT --to-ports '.ResolverService::DNS_LISTEN_PORT.' 2>/dev/null || true';
            $parts[] = 'sh /config/resolver-unmark.sh %i 2>/dev/null || true';
            // Do not wipe DIVERT — other resolver ifaces (or next PostUp) still need it.
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
        $sslEnabled = filter_var(Setting::getValue('ssl_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
        // Secure cookies only when IP is forced onto HTTPS domain; otherwise HTTP-by-IP login must work.
        $secureCookie = $sslEnabled && $this->shouldRedirectIpToDomain();

        $statefulDomains = array_values(array_filter(
            $this->resolveSanctumStatefulDomains(),
            static function (string $domain): bool {
                if ($domain === '') {
                    return false;
                }
                if (class_exists(\Laravel\Sanctum\Sanctum::class)
                    && $domain === \Laravel\Sanctum\Sanctum::$currentRequestHostPlaceholder) {
                    return false;
                }

                return true;
            }
        ));

        $values = array_merge([
            'PANEL_PORT' => $httpPort,
            'PANEL_HTTPS_PORT' => $httpsPort,
            'APP_URL' => $appUrl,
            'SESSION_SECURE_COOKIE' => $secureCookie ? 'true' : 'false',
            'SANCTUM_STATEFUL_DOMAINS' => implode(',', $statefulDomains),
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
                // Prevent .env injection via unexpected newlines in values.
                $value = str_replace(["\r", "\n"], '', (string) $value);
                if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                    continue;
                }
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
        $junk = $this->generateJunkParams($config->protocol_version);
        $config->fill($junk);
        $config->save();
        $this->applyConfig();

        return $junk;
    }
}
