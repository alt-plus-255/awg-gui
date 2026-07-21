<?php

namespace App\Services\Resolver;

use App\Models\AwgConfig;
use App\Models\ResolverConnection;
use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ResolverService
{
    public const FAKEIP_CIDR = '198.18.0.0/15';

    public const TPROXY_PORT = 1602;

    /** Plain DNS listen for WireGuard clients (gateway:53 → local delivery). */
    public const DNS_LISTEN_PORT = 53;

    /** @deprecated use DNS_LISTEN_PORT; kept for older PostUp cleanup */
    public const DNS_REDIRECT_PORT = 5353;

    public const TUN_IFACE = 'sbox0';

    public const TUN_TABLE = 101;

    public const CLASH_API_ADDR = '127.0.0.1:9090';

    public const CLASH_PROBE_API_ADDR = '127.0.0.1:9091';

    public const DELAY_TEST_URL = 'https://www.gstatic.com/generate_204';

    public const RULESET_BASE_URL = 'https://github.com/itdoginfo/allow-domains/releases/latest/download';

    /** @var list<string> */
    public const COMMUNITY_LISTS = [
        'russia_inside',
        'russia_outside',
        'ukraine_inside',
        'geoblock',
        'block',
        'porn',
        'news',
        'anime',
        'youtube',
        'hdrezka',
        'tiktok',
        'google_ai',
        'google_play',
        'hodca',
        'discord',
        'meta',
        'twitter',
        'cloudflare',
        'cloudfront',
        'digitalocean',
        'hetzner',
        'ovh',
        'telegram',
        'roblox',
    ];

    /** @var list<string> */
    public const MUTUALLY_EXCLUSIVE = [
        'russia_inside',
        'russia_outside',
        'ukraine_inside',
    ];

    public function __construct(
        private AmneziaWgService $awg,
        private ResolverPaths $paths,
        private ResolverFileHelper $files,
        private MergedRulesetWriter $mergedRulesets,
        private ResolverMarkScripts $markScripts,
        private ClashApiClient $clash,
        private ResolverDiagnostics $diagnostics,
        private ResolverListsService $lists,
    ) {}

    public static function communitySourceUrl(string $tag): string
    {
        return self::RULESET_BASE_URL.'/'.$tag.'.srs';
    }

    /** @return list<array{tag:string,label:string,exclusive_group:?string,source_url:string}> */
    public function communityListCatalog(): array
    {
        $labels = [
            'russia_inside' => 'Russia inside',
            'russia_outside' => 'Russia outside',
            'ukraine_inside' => 'Ukraine inside',
            'geoblock' => 'GEO Block',
            'block' => 'Block',
            'porn' => 'Porn',
            'news' => 'News',
            'anime' => 'Anime',
            'youtube' => 'YouTube',
            'hdrezka' => 'HDRezka',
            'tiktok' => 'TikTok',
            'google_ai' => 'Google AI',
            'google_play' => 'Google Play',
            'hodca' => 'H.O.D.C.A.',
            'discord' => 'Discord',
            'meta' => 'Meta*',
            'twitter' => 'Twitter (X)',
            'cloudflare' => 'Cloudflare',
            'cloudfront' => 'CloudFront',
            'digitalocean' => 'DigitalOcean',
            'hetzner' => 'Hetzner',
            'ovh' => 'OVH',
            'telegram' => 'Telegram',
            'roblox' => 'Roblox',
        ];

        $out = [];
        foreach (self::COMMUNITY_LISTS as $tag) {
            $out[] = [
                'tag' => $tag,
                'label' => $labels[$tag] ?? $tag,
                'kind' => 'community',
                'exclusive_group' => in_array($tag, self::MUTUALLY_EXCLUSIVE, true) ? 'region' : null,
                'source_url' => self::communitySourceUrl($tag),
            ];
        }

        return $out;
    }

    public function gatewayIp(AwgConfig $config): string
    {
        $addr = (string) $config->server_address;
        if (str_contains($addr, '/')) {
            return explode('/', $addr, 2)[0];
        }

        return $addr !== '' ? $addr : '10.66.66.1';
    }

    /**
     * Compact AllowedIPs for client_split mode.
     * Community ip_cidr intentionally omitted — kept only on VDS.
     *
     * @return list<string>
     */
    public function clientSplitAllowedIps(AwgConfig $config): array
    {
        $ips = [self::FAKEIP_CIDR, $this->gatewayIp($config).'/32'];

        foreach ($config->user_subnets ?? [] as $cidr) {
            $cidr = trim((string) $cidr);
            if ($cidr === '') {
                continue;
            }
            if (! str_contains($cidr, '/')) {
                if (filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $cidr .= '/32';
                } else {
                    continue;
                }
            }
            $ips[] = $cidr;
        }

        return array_values(array_unique($ips));
    }

    public function clientAllowedIpsPreview(AwgConfig $config): string
    {
        if (! $config->resolver_enabled) {
            return (string) ($config->client_allowed_ips ?: '');
        }

        if ($config->resolverRoutingMode() === AwgConfig::ROUTING_MODE_CLIENT_SPLIT) {
            return implode(', ', $this->clientSplitAllowedIps($config));
        }

        return '0.0.0.0/0, ::/0';
    }

    public function subnetCidr(AwgConfig $config): string
    {
        return (string) ($config->internal_subnet ?: '10.66.66.0/24');
    }

    /**
     * @param  list<string>|null  $lists
     * @param  list<string>|null  $domains
     * @param  list<string>|null  $subnets
     * @return array{community_lists:list<string>,user_domains:list<string>,user_subnets:list<string>}
     */
    public function normalizeLists(?array $lists, ?array $domains, ?array $subnets): array
    {
        $lists = array_values(array_unique(array_filter(array_map('strval', $lists ?? []))));
        $known = $this->lists->knownListTags();
        foreach ($lists as $tag) {
            if (! in_array($tag, $known, true)) {
                throw ValidationException::withMessages([
                    'community_lists' => [__('resolver.unknown_list', ['tag' => $tag])],
                ]);
            }
        }

        $exclusiveHits = array_values(array_intersect($lists, self::MUTUALLY_EXCLUSIVE));
        if (count($exclusiveHits) > 1) {
            throw ValidationException::withMessages([
                'community_lists' => [__('resolver.cannot_select_conflicting_lists')],
            ]);
        }

        $domains = $this->normalizeDomains($domains ?? []);
        $subnets = $this->normalizeSubnets($subnets ?? []);

        return [
            'community_lists' => $lists,
            'user_domains' => $domains,
            'user_subnets' => $subnets,
        ];
    }

    /** @param  list<string>  $domains */
    public function normalizeDomains(array $domains): array
    {
        $out = [];
        foreach ($domains as $raw) {
            foreach (preg_split('/[\s,;]+/', (string) $raw) ?: [] as $part) {
                $part = strtolower(trim($part));
                if ($part === '' || str_starts_with($part, '//')) {
                    continue;
                }
                $part = preg_replace('#^https?://#', '', $part) ?? $part;
                $part = explode('/', $part, 2)[0];
                $part = explode(':', $part, 2)[0];
                $part = ltrim($part, '.');
                if ($part === '' || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $part)) {
                    throw ValidationException::withMessages([
                        'user_domains' => [__('resolver.invalid_domain', ['raw' => $raw])],
                    ]);
                }
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param  list<string>  $subnets */
    public function normalizeSubnets(array $subnets): array
    {
        $out = [];
        foreach ($subnets as $raw) {
            foreach (preg_split('/[\s,;]+/', (string) $raw) ?: [] as $part) {
                $part = trim($part);
                if ($part === '' || str_starts_with($part, '//')) {
                    continue;
                }
                if (! str_contains($part, '/')) {
                    $part .= (filter_var($part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? '/32' : '');
                }
                [$host, $mask] = array_pad(explode('/', $part, 2), 2, null);
                if (! filter_var($host, FILTER_VALIDATE_IP) || $mask === null || ! ctype_digit((string) $mask)) {
                    throw ValidationException::withMessages([
                        'user_subnets' => [__('resolver.invalid_subnet', ['raw' => $raw])],
                    ]);
                }
                $maskInt = (int) $mask;
                $max = str_contains($host, ':') ? 128 : 32;
                if ($maskInt < 0 || $maskInt > $max) {
                    throw ValidationException::withMessages([
                        'user_subnets' => [__('resolver.invalid_mask', ['raw' => $raw])],
                    ]);
                }
                $out[] = $host.'/'.$maskInt;
            }
        }

        return array_values(array_unique($out));
    }

    public function assertCanEnable(AwgConfig $config): void
    {
        if ($config->type === 'virtual_network') {
            throw ValidationException::withMessages([
                'resolver_enabled' => [__('resolver.unavailable_for_vn')],
            ]);
        }
        if ($config->type !== 'server') {
            throw ValidationException::withMessages([
                'resolver_enabled' => [__('resolver.server_configs_only')],
            ]);
        }
    }

    /** @return list<AwgConfig> */
    public function enabledServerConfigs(): array
    {
        return AwgConfig::query()
            ->where('type', 'server')
            ->where('resolver_enabled', true)
            ->where('enabled', true)
            ->with('resolverConnection')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function rulesetDir(): string
    {
        return $this->paths->rulesetDir();
    }

    public function communityRulesetPath(string $tag): string
    {
        return $this->paths->communityRulesetPath($tag);
    }

    public function mergedRulesetPath(AwgConfig $config): string
    {
        return $this->paths->mergedRulesetPath($config);
    }

    public function mergedRulesetTag(AwgConfig $config): string
    {
        return $this->paths->mergedRulesetTag($config);
    }

    public function mergedIpRulesetPath(AwgConfig $config): string
    {
        return $this->paths->mergedIpRulesetPath($config);
    }

    public function mergedIpRulesetTag(AwgConfig $config): string
    {
        return $this->paths->mergedIpRulesetTag($config);
    }

    /** Union of list/user CIDRs for iptables MARK + TUN routes (not a sing-box ruleset). */
    public function proxyCidrsAllPath(): string
    {
        return $this->paths->proxyCidrsAllPath();
    }

    public function decompiledRulesetCachePath(string $tag): string
    {
        return $this->paths->decompiledRulesetCachePath($tag);
    }

    public function decompiledRulesetMetaPath(string $tag): string
    {
        return $this->paths->decompiledRulesetMetaPath($tag);
    }

    /**
     * Decompile community .srs → array.
     * In-request cache + on-disk cache keyed by .srs size/mtime (no docker when fresh).
     *
     * @return array{version?: int, rules?: list<array<string, mixed>>}
     */
    public function decompileCommunityRuleset(string $tag): array
    {
        return $this->mergedRulesets->decompileCommunityRuleset($tag);
    }

    /**
     * Collect string matchers from decompiled rules (any list: domains and/or IPs).
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<string>
     */
    public function collectRuleField(array $rules, string $key, bool $lowercase = false): array
    {
        return $this->mergedRulesets->collectRuleField($rules, $key, $lowercase);
    }

    /** Write file only when content hash differs. Returns true if written. */
    public function writeFileIfChanged(string $path, string $contents): bool
    {
        return $this->files->writeFileIfChanged($path, $contents);
    }

    /** Write shell helper and chmod +x when content changed. */
    public function writeExecutable(string $path, string $body): bool
    {
        return $this->files->writeExecutable($path, $body);
    }

    public function isSingBoxRunning(): bool
    {
        try {
            $r = Process::timeout(10)->run([
                'docker', 'exec', $this->awg->containerName(),
                'sh', '-c', 'pgrep -x sing-box >/dev/null && echo yes || echo no',
            ]);

            return trim($r->output()) === 'yes';
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param  array<string, mixed>  $payload */
    public function writeResolverStatus(array $payload): void
    {
        @file_put_contents(
            $this->resolverStatusPath(),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n"
        );
    }

    public function communityLabel(string $tag): string
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach ($this->communityListCatalog() as $item) {
                $map[$item['tag']] = $item['label'];
            }
        }

        return $map[$tag] ?? $tag;
    }

    /**
     * Merge any selected community lists + user_domains / user_subnets.
     * Domains → FakeIP DNS; IP CIDRs → selective MARK (not client AllowedIPs).
     *
     * @return array{tag: string, ip_tag: ?string, ip_cidrs: list<string>}
     */
    public function writeMergedRulesetForConfig(AwgConfig $config): array
    {
        return $this->mergedRulesets->writeMergedRulesetForConfig($config);
    }

    /**
     * Keep IPv4 CIDRs for selective MARK (DNS strategy is ipv4_only).
     *
     * @param  list<string>  $cidrs
     * @return list<string>
     */
    public function normalizeIpv4CidrsForProxy(array $cidrs): array
    {
        return $this->mergedRulesets->normalizeIpv4CidrsForProxy($cidrs);
    }

    /**
     * Write UNION of all proxy CIDRs for MARK/routes (one line per CIDR).
     *
     * @param  list<string>  $cidrs
     * @return bool true if file content changed
     */
    public function writeProxyCidrsAll(array $cidrs): bool
    {
        return $this->mergedRulesets->writeProxyCidrsAll($cidrs);
    }

    /**
     * FakeIP + list CIDR MARK helpers and TUN routes on the AWG config volume (no image rebuild).
     */
    public function ensureResolverMarkScripts(): void
    {
        $this->markScripts->ensureResolverMarkScripts();
    }

    /**
     * @param  list<AwgConfig>  $configs
     * @return list<string>
     */
    public function collectCommunityTagsFromConfigs(array $configs): array
    {
        $tags = [];
        foreach ($configs as $config) {
            foreach ($config->community_lists ?? [] as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        return array_keys($tags);
    }

    /**
     * @return array{exists: bool, size: int, mtime: ?string}
     */
    public function rulesetFileInfo(string $tag): array
    {
        $path = $this->communityRulesetPath($tag);
        if (! is_file($path)) {
            return ['exists' => false, 'size' => 0, 'mtime' => null];
        }

        $mtime = filemtime($path);

        return [
            'exists' => true,
            'size' => (int) filesize($path),
            'mtime' => $mtime !== false ? date('c', $mtime) : null,
        ];
    }

    /**
     * Download community .srs files to disk (persistent, not sing-box remote cache).
     * When $force is false, skip tags that already have a non-empty file on disk.
     *
     * @param  list<string>  $tags
     */
    public function syncCommunityRulesets(array $tags, bool $force = false): void
    {
        $community = array_values(array_filter(
            array_unique(array_map('strval', $tags)),
            fn (string $tag) => in_array($tag, self::COMMUNITY_LISTS, true)
        ));
        if ($community === []) {
            return;
        }

        $this->lists->syncCommunity($community, $force);
    }

    public function singBoxConfigPath(): string
    {
        return $this->paths->singBoxConfigPath();
    }

    public function resolverIfacesPath(): string
    {
        return $this->paths->resolverIfacesPath();
    }

    public function resolverStatusPath(): string
    {
        return $this->paths->resolverStatusPath();
    }

    /** Refresh enabled subscription connections before building sing-box config. */
    public function refreshSubscriptionConnections(): bool
    {
        $fetcher = app(SubscriptionFetcher::class);
        $fingerprint = app(ResolverConnectionSingBoxFingerprint::class);
        $connections = ResolverConnection::query()
            ->where('enabled', true)
            ->where('kind', ResolverConnection::KIND_SUBSCRIPTION)
            ->get();
        $anyChanged = false;

        foreach ($connections as $conn) {
            $url = trim((string) ($conn->subscription_url ?? ''));
            if ($url === '') {
                continue;
            }

            try {
                $hashBefore = $fingerprint->hash($conn);
                $nodes = $fetcher->fetchMerged($url);
                $existingNodes = is_array($conn->subscription_nodes) ? $conn->subscription_nodes : [];
                if (! $fingerprint->nodesEqual($existingNodes, $nodes)) {
                    $conn->subscription_nodes = $nodes;
                }
                $conn->subscription_fetched_at = now();

                if ($conn->subscription_mode === ResolverConnection::MODE_SINGLE) {
                    $selected = $conn->subscription_selected;
                    $node = null;
                    if ($selected !== null && $selected !== '') {
                        foreach ($nodes as $n) {
                            if (($n['key'] ?? null) === $selected) {
                                $node = $n;
                                break;
                            }
                        }
                    }
                    if ($node === null && $nodes !== []) {
                        $node = $nodes[0];
                        $conn->subscription_selected = $node['key'] ?? null;
                    }
                    if ($node !== null && is_array($node['outbound'] ?? null)) {
                        $conn->outbound = $node['outbound'];
                    }
                } elseif ($conn->subscription_mode === ResolverConnection::MODE_URLTEST) {
                    $conn->outbound = ['type' => 'urltest'];
                }

                if ($fingerprint->hash($conn) !== $hashBefore) {
                    $anyChanged = true;
                }

                $conn->save();
            } catch (\Throwable $e) {
                Log::warning("subscription refresh failed for conn {$conn->id}: {$e->getMessage()}");
            }
        }

        if ($anyChanged) {
            try {
                app(PingProbeConfigSync::class)->rebuildAndMaybeReload();
            } catch (\Throwable $e) {
                Log::warning('ping probe config sync after subscription refresh: '.$e->getMessage());
            }
        }

        return $anyChanged;
    }

    /**
     * Rebuild sing-box config + iface list from DB. Safe to call after every applyConfig.
     * Keeps sing-box running when resolver is on OR enabled connections exist (for stats/tests).
     *
     * @param  bool  $forceSyncLists  re-download community .srs (refresh button / artisan); save keeps local files
     */
    public function apply(bool $refreshSubscriptions = true, bool $urltestRoutingRetry = true, bool $forceSyncLists = false): void
    {
        $configs = $this->enabledServerConfigs();
        $hasConnections = ResolverConnection::query()->where('enabled', true)->exists();
        $dir = $this->awg->configDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($configs === [] && ! $hasConnections) {
            @unlink($this->singBoxConfigPath());
            @file_put_contents($this->resolverIfacesPath(), "");
            @file_put_contents($this->proxyCidrsAllPath(), '');
            @file_put_contents($this->resolverStatusPath(), json_encode([
                'enabled' => false,
                'healthy' => true,
                'message' => __('resolver.disabled'),
                'updated_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->reloadSingBox();

            return;
        }

        try {
            if ($refreshSubscriptions) {
                $this->refreshSubscriptionConnections();
            }

            $urltestRoutingBefore = [];
            foreach ($configs as $config) {
                $config->loadMissing('resolverConnection');
                $conn = $config->resolverConnection;
                if ($conn && $conn->isUrltestMode()) {
                    $urltestRoutingBefore[$conn->id] = $this->routingOutboundTag($conn);
                }
            }

            $this->mergedRulesets->resetChangeFlags();

            $sb = $this->buildSingBoxConfig($configs, $forceSyncLists);
            $json = json_encode($sb, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($json === false) {
                throw new RuntimeException(__('resolver.singbox_serialize_failed'));
            }
            $json .= "\n";

            $singBoxPath = $this->singBoxConfigPath();
            $singBoxChanged = $this->writeFileIfChanged($singBoxPath, $json);

            $ifaces = array_map(fn (AwgConfig $c) => $c->iface, $configs);
            $ifacesContents = implode("\n", $ifaces)."\n";
            $ifacesChanged = $this->writeFileIfChanged($this->resolverIfacesPath(), $ifacesContents);

            $this->ensureResolverMarkScripts();
            if ($this->mergedRulesets->applyProxyCidrsChanged || $ifacesChanged) {
                $this->refreshResolverMarksOnIfaces($ifaces);
            }

            $now = now();
            foreach ($configs as $config) {
                $config->resolver_updated_at = $now;
                $config->resolver_last_error = null;
                $config->save();
            }

            @file_put_contents($this->resolverStatusPath(), json_encode([
                'enabled' => $configs !== [],
                'healthy' => true,
                'message' => $configs !== [] ? __('resolver.config_applied') : __('resolver.connections_active_resolver_off'),
                'ifaces' => $ifaces,
                'updated_at' => $now->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            if ($singBoxChanged || $this->mergedRulesets->applyMergedChanged || $this->mergedRulesets->applyProxyCidrsChanged) {
                $this->reloadSingBox();
            }

            if ($urltestRoutingRetry && $urltestRoutingBefore !== [] && $this->waitForClashApi(25, 200)) {
                foreach ($urltestRoutingBefore as $connId => $tagBefore) {
                    $conn = ResolverConnection::query()->find($connId);
                    if ($conn && $this->routingOutboundTag($conn) !== $tagBefore) {
                        $this->apply($refreshSubscriptions, false, false);

                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('resolver apply failed: '.$e->getMessage());
            foreach ($configs as $config) {
                $config->resolver_last_error = $e->getMessage();
                $config->save();
            }
            @file_put_contents($this->resolverStatusPath(), json_encode([
                'enabled' => $configs !== [],
                'healthy' => false,
                'message' => $e->getMessage(),
                'updated_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            throw $e;
        }
    }

    /**
     * @param  list<AwgConfig>  $configs
     * @return array<string, mixed>
     */
    public function buildSingBoxConfig(array $configs, bool $forceSyncLists = false): array
    {
        $communityTags = $this->collectCommunityTagsFromConfigs($configs);
        if ($forceSyncLists) {
            $this->syncCommunityRulesets($communityTags, true);
        } else {
            $this->lists->assertSelectedListsOnDisk($communityTags);
        }

        $ruleSets = [];
        $dnsRules = [];
        $routeRules = [
            [
                'protocol' => 'dns',
                'action' => 'hijack-dns',
            ],
        ];
        $outbounds = [
            [
                'type' => 'direct',
                'tag' => 'direct',
            ],
        ];
        $outboundTagsAdded = ['direct' => true];

        $allConnections = ResolverConnection::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        $built = app(ConnectionOutboundBuilder::class)->buildForConnections($allConnections);
        $outbounds = $built['outbounds'];
        $outboundTagsAdded = $built['tags_added'];

        $allProxyCidrs = [];
        $quicRejectRules = [];

        foreach ($configs as $config) {
            $config->loadMissing('resolverConnection');
            $routingTag = $this->routingTagForConfig($config);
            $source = [$this->subnetCidr($config)];
            $lists = array_values($config->community_lists ?? []);
            $domains = array_values($config->user_domains ?? []);
            $subnets = array_values($config->user_subnets ?? []);

            if ($config->resolver_reject_quic) {
                $quicRejectRules[] = [
                    'source_ip_cidr' => $source,
                    'ip_cidr' => [self::FAKEIP_CIDR],
                    'network' => 'udp',
                    'port' => 443,
                    'action' => 'reject',
                ];
            }

            foreach ($lists as $tag) {
                $localPath = $this->communityRulesetPath($tag);
                if (! is_file($localPath)) {
                    throw new RuntimeException(__('resolver.ruleset_not_on_disk_refresh', ['tag' => $tag]));
                }
            }

            if ($lists === [] && $domains === [] && $subnets === []) {
                // No lists: still pin this config's FakeIP answers (if any) to its outbound.
                $routeRules[] = [
                    'source_ip_cidr' => $source,
                    'ip_cidr' => [self::FAKEIP_CIDR],
                    'outbound' => $routingTag,
                ];

                continue;
            }

            $merged = $this->writeMergedRulesetForConfig($config);
            $mergedTag = $merged['tag'];
            $ruleSets[] = [
                'type' => 'local',
                'tag' => $mergedTag,
                'format' => 'source',
                'path' => '/config/rulesets/merged_cfg_'.$config->id.'.json',
            ];

            if ($lists !== [] || $domains !== []) {
                $dnsRules[] = [
                    'source_ip_cidr' => $source,
                    'rule_set' => [$mergedTag],
                    'server' => 'fakeip',
                ];
                // Domain rules without source_ip: FakeIP/sniff rewrite can break
                // source+dest AND matching on TUN; inbound+rule_set is reliable.
                $routeRules[] = [
                    'inbound' => ['tun-in'],
                    'rule_set' => [$mergedTag],
                    'outbound' => $routingTag,
                ];
            }

            if (! empty($merged['ip_tag']) && ! empty($merged['ip_cidrs'])) {
                $ruleSets[] = [
                    'type' => 'local',
                    'tag' => $merged['ip_tag'],
                    'format' => 'source',
                    'path' => '/config/rulesets/merged_cfg_'.$config->id.'_ip.json',
                ];
                $routeRules[] = [
                    'inbound' => ['tun-in'],
                    'source_ip_cidr' => $source,
                    'rule_set' => [$merged['ip_tag']],
                    'outbound' => $routingTag,
                ];
                foreach ($merged['ip_cidrs'] as $cidr) {
                    $allProxyCidrs[] = $cidr;
                }
            }

            // FakeIP for this VPN subnet → this config's outbound (never shared catch-all).
            $routeRules[] = [
                'source_ip_cidr' => $source,
                'ip_cidr' => [self::FAKEIP_CIDR],
                'outbound' => $routingTag,
            ];
        }

        $this->writeProxyCidrsAll($allProxyCidrs);

        if ($quicRejectRules !== []) {
            array_splice($routeRules, 1, 0, $quicRejectRules);
        }

        $dnsRules[] = [
            'server' => 'remote',
        ];

        $fallbackDns = '1.1.1.1';
        $fallbackSet = false;
        $dnsServers = [
            [
                'type' => 'udp',
                'tag' => 'bootstrap',
                'server' => '8.8.8.8',
                'server_port' => 53,
            ],
        ];

        foreach ($configs as $config) {
            $upstream = trim((string) ($config->resolver_dns ?: ''));
            if ($upstream === '') {
                $upstream = '1.1.1.1';
            }
            if (! $fallbackSet) {
                $fallbackDns = $upstream;
                $fallbackSet = true;
            }
            $tag = 'remote_cfg_'.$config->id;
            $dnsServers[] = [
                'type' => 'udp',
                'tag' => $tag,
                'server' => $upstream,
                'server_port' => 53,
            ];
            // Insert before the final catch-all rule
            array_splice($dnsRules, -1, 0, [[
                'source_ip_cidr' => [$this->subnetCidr($config)],
                'server' => $tag,
            ]]);
        }

        $dnsServers[] = [
            'type' => 'udp',
            'tag' => 'remote',
            'server' => $fallbackDns,
            'server_port' => 53,
        ];
        $dnsServers[] = [
            'type' => 'fakeip',
            'tag' => 'fakeip',
            'inet4_range' => self::FAKEIP_CIDR,
        ];

        return [
            'log' => [
                'level' => 'info',
                'timestamp' => true,
            ],
            'dns' => [
                'servers' => $dnsServers,
                'rules' => $dnsRules,
                'final' => 'remote',
                'independent_cache' => true,
                'strategy' => 'ipv4_only',
            ],
            'inbounds' => [
                [
                    'type' => 'direct',
                    'tag' => 'dns-in',
                    'listen' => '0.0.0.0',
                    'listen_port' => self::DNS_LISTEN_PORT,
                    'sniff' => true,
                ],
                [
                    'type' => 'tun',
                    'tag' => 'tun-in',
                    'interface_name' => self::TUN_IFACE,
                    // Keep off Docker bridge ranges (often 172.16–172.19) to avoid route clashes.
                    'address' => ['10.255.255.1/30'],
                    // Stay under AmneziaWG MTU (1420) to avoid blackhole fragments.
                    'mtu' => 1280,
                    'auto_route' => false,
                    'strict_route' => false,
                    'stack' => 'system',
                    'sniff' => true,
                    // Must stay false: override replaces 198.18.x with the domain and
                    // breaks the FakeIP ip_cidr → proxy route (traffic falls to direct).
                    'sniff_override_destination' => false,
                ],
            ],
            'outbounds' => $outbounds,
            'route' => [
                'rules' => $routeRules,
                'rule_set' => $ruleSets,
                'final' => 'direct',
                'auto_detect_interface' => true,
                'default_domain_resolver' => 'bootstrap',
            ],
            'experimental' => [
                'cache_file' => [
                    'enabled' => true,
                    'path' => '/config/sing-box-cache.db',
                    'store_rdrc' => true,
                ],
                'clash_api' => [
                    'external_controller' => self::CLASH_API_ADDR,
                    'default_mode' => 'rule',
                ],
            ],
        ];
    }

    /**
     * @return array{ok: bool, status: int, body: ?array, raw: string, error: ?string}
     */
    public function clashApiRequest(string $path, array $query = [], int $timeoutSec = 15): array
    {
        return $this->clash->clashApiRequest($path, $query, $timeoutSec);
    }

    public function waitForClashApi(int $attempts = 25, int $sleepMs = 200): bool
    {
        return $this->clash->waitForClashApi($attempts, $sleepMs);
    }

    /**
     * Aggregate RX/TX (download/upload) from Clash /connections by outbound tag.
     *
     * @return array<string, array{rx: int, tx: int, active: bool}>
     */
    public function trafficByOutboundTag(): array
    {
        return $this->clash->trafficByOutboundTag();
    }

    /**
     * Latency test via Clash API GET /proxies/{tag}/delay
     *
     * @return array{ok: bool, latency_ms: ?int, error: ?string}
     */
    public function testOutboundDelay(string $tag, int $timeoutMs = 5000): array
    {
        return $this->clash->testOutboundDelay($tag, $timeoutMs);
    }

    public function routingOutboundTag(ResolverConnection $conn): string
    {
        if (! $conn->isUrltestMode()) {
            return $conn->outboundTag();
        }

        $parentTag = $conn->outboundTag();
        $fallback = $conn->childOutboundTag(1);

        $resp = $this->clashApiRequest('/proxies/'.rawurlencode($parentTag), [], 3);
        if ($resp['ok'] && is_array($resp['body'])) {
            $now = $resp['body']['now'] ?? null;
            if (is_string($now) && $now !== '' && str_starts_with($now, $parentTag.'_')) {
                return $now;
            }
        }

        return $fallback;
    }

    public function routingTagForConfig(AwgConfig $config): string
    {
        $conn = $config->resolverConnection;
        if (! $conn || ! $conn->enabled) {
            return 'direct';
        }
        if ($conn->isUrltestMode()) {
            return $this->routingOutboundTag($conn);
        }
        if (is_array($conn->outbound) && ! empty($conn->outbound['type'])) {
            return $conn->outboundTag();
        }

        return 'direct';
    }

    public function assertConnectionSelected(AwgConfig $config, ?int $connectionId): ResolverConnection
    {
        if (! $connectionId) {
            throw ValidationException::withMessages([
                'connection_id' => [__('resolver.select_connection')],
            ]);
        }
        $conn = ResolverConnection::query()->find($connectionId);
        if (! $conn) {
            throw ValidationException::withMessages([
                'connection_id' => [__('resolver.connection_not_found')],
            ]);
        }
        if (! $conn->enabled) {
            throw ValidationException::withMessages([
                'connection_id' => [__('resolver.connection_disabled')],
            ]);
        }

        return $conn;
    }

    public function reloadSingBox(): void
    {
        $container = $this->awg->containerName();
        try {
            // Prefer volume copy (list CIDR routes) so AWG image rebuild is not required.
            Process::timeout(30)->run([
                'docker', 'exec', $container,
                'sh', '-c',
                'if [ -x /config/reload-singbox.sh ]; then /config/reload-singbox.sh; else /usr/local/bin/reload-singbox.sh; fi',
            ]);
        } catch (\Throwable $e) {
            Log::warning('reload-singbox: '.$e->getMessage());
        }
    }

    /**
     * Re-apply MARK chains on live AWG ifaces after proxy_cidrs_all.lst changes.
     * Single docker exec for all ifaces (avoids O(N) round-trips).
     *
     * @param  list<string>  $ifaces
     */
    public function refreshResolverMarksOnIfaces(array $ifaces): void
    {
        $this->markScripts->refreshResolverMarksOnIfaces($ifaces);
    }

    /**
     * Runtime checks for FakeIP path (sing-box, iptables, sample DNS).
     *
     * @return array<string, mixed>
     */
    public function diagnose(): array
    {
        return $this->diagnostics->diagnose($this);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $path = $this->resolverStatusPath();
        $file = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $file = $decoded;
            }
        }

        $configs = AwgConfig::query()
            ->where('type', 'server')
            ->with('resolverConnection')
            ->orderBy('id')
            ->get();

        $connections = ResolverConnection::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ResolverConnection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'comment' => $c->comment,
                'config_type' => $c->config_type,
                'outbound_type' => $c->outbound['type'] ?? null,
                'enabled' => (bool) $c->enabled,
                'tag' => $c->outboundTag(),
            ])
            ->values()
            ->all();

        $enabledCount = $configs->where('resolver_enabled', true)->count();
        $singBoxRunning = $this->isSingBoxRunning();

        return [
            'enabled' => $enabledCount > 0,
            'healthy' => $enabledCount === 0
                ? true
                : ($singBoxRunning && (bool) ($file['healthy'] ?? true) && ! $configs->where('resolver_enabled', true)->contains(fn ($c) => filled($c->resolver_last_error))),
            'singbox_running' => $singBoxRunning,
            'fakeip_cidr' => self::FAKEIP_CIDR,
            'message' => $file['message'] ?? ($enabledCount > 0 ? 'OK' : __('resolver.disabled')),
            'updated_at' => $file['updated_at'] ?? null,
            'community_lists' => $this->communityListCatalog(),
            'custom_lists' => $this->lists->customListCatalog(),
            'connections' => $connections,
            'configs' => $configs->map(function (AwgConfig $c) {
                $conn = $c->resolverConnection;

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'iface' => $c->iface,
                    'type' => $c->type,
                    'enabled' => (bool) $c->enabled,
                    'resolver_enabled' => (bool) $c->resolver_enabled,
                    'resolver_routing_mode' => $c->resolverRoutingMode(),
                    'resolver_reject_quic' => (bool) $c->resolver_reject_quic,
                    'connection_id' => $c->connection_id,
                    'connection_name' => $conn?->name,
                    'connection_tag' => $conn ? $conn->outboundTag() : null,
                    'community_lists' => array_values($c->community_lists ?? []),
                    'user_domains' => array_values($c->user_domains ?? []),
                    'user_subnets' => array_values($c->user_subnets ?? []),
                    'resolver_updated_at' => optional($c->resolver_updated_at)?->toIso8601String(),
                    'resolver_last_error' => $c->resolver_last_error,
                    'gateway_ip' => $this->gatewayIp($c),
                    'resolver_dns' => $c->resolver_dns ?: '1.1.1.1',
                    'client_dns' => $c->resolver_enabled ? $this->gatewayIp($c) : $c->peer_dns,
                    'client_allowed_ips_preview' => $this->clientAllowedIpsPreview($c),
                ];
            })->values()->all(),
        ];
    }
}
