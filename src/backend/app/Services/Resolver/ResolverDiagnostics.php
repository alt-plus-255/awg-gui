<?php

namespace App\Services\Resolver;

use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;

class ResolverDiagnostics
{
    /** @var array<string, string> */
    public const LIST_PROBE_DOMAINS = [
        'russia_inside' => 'netflix.com',
        'russia_outside' => 'google.com',
        'ukraine_inside' => 'ukr.net',
        'geoblock' => 'netflix.com',
        'block' => 'reddit.com',
        'porn' => 'pornhub.com',
        'news' => 'bbc.com',
        'anime' => 'animego.org',
        'youtube' => 'youtube.com',
        'hdrezka' => 'hdrezka.ag',
        'tiktok' => 'tiktok.com',
        'google_ai' => 'gemini.google.com',
        'google_play' => 'play.google.com',
        'hodca' => 'hodca.com',
        'discord' => 'discord.com',
        'meta' => 'instagram.com',
        'twitter' => 'x.com',
        'cloudflare' => 'cloudflare.com',
        'cloudfront' => 'cloudfront.net',
        'digitalocean' => 'digitalocean.com',
        'hetzner' => 'hetzner.com',
        'ovh' => 'ovh.com',
        'telegram' => 'telegram.org',
        'roblox' => 'roblox.com',
    ];


    public function __construct(
        private AmneziaWgService $awg,
        private DockerRuntime $docker,
        private ResolverPaths $paths,
        private ClashApiClient $clash,
    ) {}

    /**
     * Runtime checks for FakeIP path (sing-box, iptables, sample DNS).
     *
     * @return array<string, mixed>
     */
    public function diagnose(ResolverService $resolver): array
    {
        $checks = [];
        $hints = [];
        $container = $this->awg->containerName();
        $enabled = $resolver->enabledServerConfigs();
        $enabledIfaces = array_values(array_filter(array_map(
            fn ($cfg) => trim((string) $cfg->iface),
            $enabled
        )));

        $singBoxRunning = $resolver->isSingBoxRunning();
        $checks[] = [
            'id' => 'singbox_running',
            'ok' => $singBoxRunning,
            'label' => __('resolver.diag_singbox_running'),
            'detail' => $singBoxRunning ? 'OK' : __('resolver.diag_process_not_found'),
        ];
        if (! $singBoxRunning) {
            $hints[] = __('resolver.diag_apply_resolver_hint');
        }

        $runtime = $this->collectRuntimeSignals($container, $enabledIfaces);
        $dnsListening = $runtime['listeners']['dns_udp'] || $runtime['listeners']['dns_tcp'];
        $tproxyListening = $runtime['listeners']['tproxy_udp'] || $runtime['listeners']['tproxy_tcp'];
        $fakeipTproxy = $runtime['iptables']['fakeip_rules_present'];
        $fakeipHits = $runtime['iptables']['tproxy_fakeip_tcp_hits'] + $runtime['iptables']['tproxy_fakeip_udp_hits'];
        $listHits = $runtime['iptables']['tproxy_list_tcp_hits'] + $runtime['iptables']['tproxy_list_udp_hits'];
        $rsHits = array_sum($runtime['iptables']['prerouting_rs_hits_by_iface']);
        $deliveryMode = ($runtime['config']['delivery_inbound_type'] ?? '') === 'redirect' ? 'redirect' : 'tproxy';

        $checks[] = [
            'id' => 'dns_listen',
            'ok' => $dnsListening,
            'label' => 'DNS listen :'.ResolverService::DNS_LISTEN_PORT,
            'detail' => $dnsListening ? __('resolver.diag_dns_listening', ['port' => ResolverService::DNS_LISTEN_PORT]) : __('resolver.diag_dns_not_listening', ['port' => ResolverService::DNS_LISTEN_PORT]),
        ];
        $checks[] = [
            'id' => 'fakeip_tproxy',
            'ok' => $tproxyListening && $fakeipTproxy,
            'label' => $deliveryMode === 'redirect'
                ? 'FakeIP → REDIRECT :'.ResolverService::TPROXY_PORT
                : 'FakeIP → TPROXY :'.ResolverService::TPROXY_PORT,
            'detail' => $tproxyListening
                ? 'mode='.$deliveryMode
                    .', listen='.($runtime['config']['tproxy_listen_addr'] ?: 'n/a').':'.($runtime['config']['tproxy_listen_port'] ?: ResolverService::TPROXY_PORT)
                    .", fakeip_hits={$fakeipHits}, list_hits={$listHits}, rs_hits={$rsHits}"
                : __('resolver.diag_tproxy_down', ['port' => ResolverService::TPROXY_PORT]),
        ];
        if ($tproxyListening && $fakeipHits === 0 && $rsHits > 0) {
            $hints[] = __('resolver.diag_rs_without_fakeip');
        } elseif ($tproxyListening && $fakeipHits === 0) {
            $hints[] = __('resolver.diag_no_fakeip_traffic');
        }

        if ($deliveryMode === 'redirect') {
            // NAT REDIRECT does not use fwmark/table 100 (that was for TPROXY→127.0.0.1).
            $checks[] = [
                'id' => 'tproxy_policy',
                'ok' => true,
                'label' => __('resolver.diag_delivery_mode_redirect'),
                'detail' => __('resolver.diag_delivery_mode_redirect_detail'),
            ];
        } else {
            $checks[] = [
                'id' => 'tproxy_policy',
                'ok' => $runtime['policy_routing']['ip_rule_has_tproxy_mark'] && $runtime['policy_routing']['ip_route_table_100_local_default'],
                'label' => 'Policy routing fwmark '.ResolverService::TPROXY_MARK.' → table '.ResolverService::TPROXY_TABLE,
                'detail' => 'ip_rule='
                    .($runtime['policy_routing']['ip_rule_has_tproxy_mark'] ? 'ok' : 'missing')
                    .', table100='
                    .($runtime['policy_routing']['ip_route_table_100_local_default'] ? 'ok' : 'missing'),
            ];
            if (! $runtime['policy_routing']['ip_rule_has_tproxy_mark'] || ! $runtime['policy_routing']['ip_route_table_100_local_default']) {
                $hints[] = __('resolver.diag_tproxy_no_sessions', [
                    'table' => ResolverService::TPROXY_TABLE,
                    'port' => ResolverService::TPROXY_PORT,
                ]);
            }
        }

        $clashOk = $this->clash->waitForClashApi(5, 150);
        $checks[] = [
            'id' => 'clash_api',
            'ok' => $clashOk,
            'label' => 'Clash API',
            'detail' => $clashOk ? __('resolver.diag_available') : __('resolver.diag_unavailable'),
        ];

        $clashConns = 0;
        $clashChains = [];
        if ($clashOk) {
            $connResp = $this->clash->clashApiRequest('/connections', [], 5);
            if (is_array($connResp['body']['connections'] ?? null)) {
                $clashConns = count($connResp['body']['connections']);
                foreach ($connResp['body']['connections'] as $conn) {
                    if (! is_array($conn)) {
                        continue;
                    }
                    foreach ($conn['chains'] ?? [] as $tag) {
                        if (is_string($tag) && $tag !== '') {
                            $clashChains[$tag] = true;
                        }
                    }
                }
            }
        }
        $runtime['clash'] = [
            'api_ok' => $clashOk,
            'connections_current' => $clashConns,
            'connection_chains' => array_values(array_keys($clashChains)),
        ];
        if ($fakeipHits > 20 && $clashConns === 0) {
            $checks[] = [
                'id' => 'tproxy_delivery',
                'ok' => false,
                'label' => __('resolver.diag_fakeip_delivery'),
                'detail' => "fakeip_hits={$fakeipHits}, clash_connections_current=0, rs_hits={$rsHits}",
            ];
            $hints[] = __('resolver.diag_tproxy_no_sessions', [
                'table' => ResolverService::TPROXY_TABLE,
                'port' => ResolverService::TPROXY_PORT,
            ]);
        } elseif ($rsHits > 20 && $fakeipHits === 0) {
            $checks[] = [
                'id' => 'tproxy_delivery',
                'ok' => false,
                'label' => __('resolver.diag_fakeip_delivery'),
                'detail' => "rs_hits={$rsHits}, fakeip_hits=0, clash_connections_current={$clashConns}",
            ];
        } elseif ($clashConns > 0) {
            $checks[] = [
                'id' => 'tproxy_delivery',
                'ok' => true,
                'label' => __('resolver.diag_fakeip_delivery'),
                'detail' => __('resolver.diag_active_clash_connections', ['count' => $clashConns]),
            ];
        }

        $enabledTags = $resolver->collectCommunityTagsFromConfigs($enabled);
        $dnsSamples = [];

        $statusFile = [];
        $statusRaw = @file_get_contents($this->paths->resolverStatusPath());
        if (is_string($statusRaw) && $statusRaw !== '') {
            $decoded = json_decode($statusRaw, true);
            if (is_array($decoded)) {
                $statusFile = $decoded;
            }
        }
        $fileHealthy = (bool) ($statusFile['healthy'] ?? true);
        $fileMessage = trim((string) ($statusFile['message'] ?? ''));
        $fileErrorAt = trim((string) ($statusFile['error_at'] ?? ''));
        $configApplyErrors = [];
        foreach ($enabled as $cfg) {
            if (filled($cfg->resolver_last_error)) {
                $configApplyErrors[] = $cfg->name.': '.$cfg->resolver_last_error;
            }
        }
        $applyOk = $fileHealthy && $configApplyErrors === [];
        $applyDetail = $applyOk
            ? ($fileMessage !== '' ? $fileMessage : 'OK')
            : ($fileMessage !== ''
                ? $fileMessage.($configApplyErrors !== [] ? ' · '.implode('; ', $configApplyErrors) : '')
                : ($configApplyErrors !== [] ? implode('; ', $configApplyErrors) : __('resolver.apply_failed_after_save')));
        if (! $applyOk && $fileErrorAt !== '') {
            $applyDetail .= ' @ '.$fileErrorAt;
        }
        $checks[] = [
            'id' => 'resolver_apply',
            'ok' => $applyOk,
            'label' => __('resolver.diag_apply_status'),
            'detail' => $applyDetail,
        ];
        if (! $applyOk) {
            $hints[] = __('resolver.diag_apply_failed_hint');
        }

        foreach ($enabledTags as $tag) {
            $info = $resolver->rulesetFileInfo($tag);
            $label = $resolver->communityLabel($tag);
            $checks[] = [
                'id' => 'ruleset_'.$tag,
                'ok' => $info['exists'] && $info['size'] > 0,
                'label' => 'Ruleset '.$label,
                'detail' => $info['exists']
                    ? ($tag.'.srs · '.number_format($info['size']).' B'.($info['mtime'] ? ' · '.$info['mtime'] : ''))
                    : __('resolver.diag_ruleset_missing', ['tag' => $tag]),
            ];
            if (! $info['exists'] || $info['size'] === 0) {
                $hints[] = __('resolver.diag_list_file_missing', ['label' => $label, 'tag' => $tag]);
            }
        }

        foreach ($enabled as $cfg) {
            $mergedPath = $this->paths->mergedRulesetPath($cfg);
            $ok = is_file($mergedPath) && filesize($mergedPath) > 0;
            $detail = $ok
                ? ('merged_cfg_'.$cfg->id.'.json · '.number_format((int) filesize($mergedPath)).' B')
                : __('resolver.diag_merged_missing', ['id' => $cfg->id]);
            if (! $ok && filled($cfg->resolver_last_error)) {
                $detail .= ' · '.$cfg->resolver_last_error;
            }
            $checks[] = [
                'id' => 'merged_cfg_'.$cfg->id,
                'ok' => $ok,
                'label' => 'Merged domains «'.$cfg->name.'»',
                'detail' => $detail,
            ];

            if (! $ok) {
                $hints[] = filled($cfg->resolver_last_error)
                    ? __('resolver.diag_merged_missing_with_error', [
                        'name' => $cfg->name,
                        'error' => $cfg->resolver_last_error,
                    ])
                    : __('resolver.diag_merged_missing_hint', ['name' => $cfg->name]);
            }

            $ipPath = $this->paths->mergedIpRulesetPath($cfg);
            if (is_file($ipPath) && filesize($ipPath) > 0) {
                $checks[] = [
                    'id' => 'merged_cfg_'.$cfg->id.'_ip',
                    'ok' => true,
                    'label' => 'Merged IPs «'.$cfg->name.'»',
                    'detail' => 'merged_cfg_'.$cfg->id.'_ip.json · '.number_format((int) filesize($ipPath)).' B',
                ];
            } elseif (($cfg->user_subnets ?? []) !== []) {
                $checks[] = [
                    'id' => 'merged_cfg_'.$cfg->id.'_ip',
                    'ok' => false,
                    'label' => 'Merged IPs «'.$cfg->name.'»',
                    'detail' => __('resolver.diag_ip_merge_missing'),
                ];
                $hints[] = __('resolver.diag_ip_merge_hint', ['name' => $cfg->name]);
            }
        }

        $proxyLst = $this->paths->proxyCidrsAllPath();
        if (is_file($proxyLst) && filesize($proxyLst) > 0) {
            $lineCount = count(array_filter(file($proxyLst, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []));
            $checks[] = [
                'id' => 'proxy_cidrs_all',
                'ok' => true,
                'label' => 'UNION list CIDRs (MARK)',
                'detail' => __('resolver.diag_proxy_cidrs_prefixes', ['count' => $lineCount]),
            ];
        }

        if ($enabled !== [] && $singBoxRunning) {
            $gw = $resolver->gatewayIp($enabled[0]);
            foreach ($enabledTags as $tag) {
                $domain = self::LIST_PROBE_DOMAINS[$tag] ?? null;
                if ($domain === null) {
                    continue;
                }
                $label = $resolver->communityLabel($tag);
                $sample = $this->probeDnsViaGateway($gw, $domain);
                $isFake = is_string($sample['address'] ?? null)
                    && str_starts_with((string) $sample['address'], '198.18.');
                $checks[] = [
                    'id' => 'dns_fakeip_'.$tag,
                    'ok' => (bool) ($sample['ok'] ?? false) && $isFake,
                    'label' => 'DNS '.$label.' ('.$domain.')',
                    'detail' => $sample['detail'] ?? 'n/a',
                ];
                $dnsSamples[$tag] = $sample;
                if (($sample['ok'] ?? false) && ! $isFake) {
                    $hints[] = __('resolver.diag_dns_not_fakeip', ['domain' => $domain, 'label' => $label]);
                } elseif (! ($sample['ok'] ?? false)) {
                    $hints[] = __('resolver.diag_dns_no_reply', ['domain' => $domain, 'label' => $label]);
                }
            }
        }

        $clientHints = [
            __('resolver.diag_client_hint_reimport'),
            __('resolver.diag_client_hint_conf'),
            __('resolver.diag_client_hint_2ip'),
            __('resolver.diag_client_hint_android'),
            __('resolver.diag_client_hint_iphone'),
            __('resolver.diag_client_hint_tspu'),
        ];

        $allOk = ! in_array(false, array_column($checks, 'ok'), true);

        return [
            'ok' => $allOk,
            'checks' => $checks,
            'hints' => array_values(array_unique([...$hints, ...$clientHints])),
            'fakeip_cidr' => ResolverService::FAKEIP_CIDR,
            'details' => $runtime,
            'dns_samples' => $dnsSamples,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  list<string>  $enabledIfaces
     * @return array{
     *   listeners: array{dns_udp: bool,dns_tcp: bool,tproxy_udp: bool,tproxy_tcp: bool},
     *   config: array{tproxy_listen_addr: ?string,tproxy_listen_port: ?int,dns_listen_addr: ?string,dns_listen_port: ?int},
     *   policy_routing: array{ip_rule_has_tproxy_mark: bool,ip_route_table_100_local_default: bool,ip_rule_lines: list<string>,ip_route_table_100_lines: list<string>},
     *   iptables: array{
     *     prerouting_rs_hits_by_iface: array<string,int>,
     *     divert_tcp_hits: int,
     *     divert_udp_hits: int,
     *     tproxy_fakeip_tcp_hits: int,
     *     tproxy_fakeip_udp_hits: int,
     *     tproxy_list_tcp_hits: int,
     *     tproxy_list_udp_hits: int,
     *     fakeip_rules_present: bool,
     *     nat_dns_redirect_hits: int
     *   },
     *   clash?: array{api_ok: bool,connections_current: int,connection_chains: list<string>}
     * }
     */
    private function collectRuntimeSignals(string $container, array $enabledIfaces): array
    {
        $signals = [
            'listeners' => [
                'dns_udp' => false,
                'dns_tcp' => false,
                'tproxy_udp' => false,
                'tproxy_tcp' => false,
            ],
            'config' => [
                'tproxy_listen_addr' => null,
                'tproxy_listen_port' => null,
                'dns_listen_addr' => null,
                'dns_listen_port' => null,
                'delivery_inbound_type' => null,
            ],
            'policy_routing' => [
                'ip_rule_has_tproxy_mark' => false,
                'ip_route_table_100_local_default' => false,
                'ip_rule_lines' => [],
                'ip_route_table_100_lines' => [],
            ],
            'iptables' => [
                'prerouting_rs_hits_by_iface' => [],
                'divert_tcp_hits' => 0,
                'divert_udp_hits' => 0,
                'tproxy_fakeip_tcp_hits' => 0,
                'tproxy_fakeip_udp_hits' => 0,
                'tproxy_list_tcp_hits' => 0,
                'tproxy_list_udp_hits' => 0,
                'fakeip_rules_present' => false,
                'nat_dns_redirect_hits' => 0,
            ],
        ];

        $config = $this->readSingBoxRuntimeConfig();
        if ($config !== []) {
            $signals['config'] = array_merge($signals['config'], $config);
        }

        try {
            $script = <<<'SH'
echo "__SS_UDP__"
ss -ulnp 2>/dev/null || true
echo "__SS_TCP__"
ss -tlnp 2>/dev/null || true
echo "__IP_RULE__"
ip rule show 2>/dev/null || true
echo "__IP_ROUTE_100__"
ip route show table 100 2>/dev/null || true
echo "__MANGLE_SAVE__"
iptables-save -t mangle -c 2>/dev/null || true
echo "__NAT_SAVE__"
iptables-save -t nat -c 2>/dev/null || true
SH;
            $r = $this->docker->exec($container, ['sh', '-c', $script], timeout: 10);
            $sections = $this->splitSections($r->output());
            $udp = $sections['__SS_UDP__'] ?? [];
            $tcp = $sections['__SS_TCP__'] ?? [];
            $ipRule = $sections['__IP_RULE__'] ?? [];
            $route100 = $sections['__IP_ROUTE_100__'] ?? [];
            $mangleSave = $sections['__MANGLE_SAVE__'] ?? [];
            $natSave = $sections['__NAT_SAVE__'] ?? [];

            $signals['listeners']['dns_udp'] = $this->hasListenerOnPort($udp, ResolverService::DNS_LISTEN_PORT);
            $signals['listeners']['dns_tcp'] = $this->hasListenerOnPort($tcp, ResolverService::DNS_LISTEN_PORT);
            $signals['listeners']['tproxy_udp'] = $this->hasListenerOnPort($udp, ResolverService::TPROXY_PORT);
            $signals['listeners']['tproxy_tcp'] = $this->hasListenerOnPort($tcp, ResolverService::TPROXY_PORT);

            $signals['policy_routing']['ip_rule_lines'] = $ipRule;
            $signals['policy_routing']['ip_route_table_100_lines'] = $route100;
            $signals['policy_routing']['ip_rule_has_tproxy_mark'] = $this->hasTproxyRule($ipRule);
            $signals['policy_routing']['ip_route_table_100_local_default'] = $this->hasTproxyRoute($route100);

            $signals['iptables'] = array_merge(
                $signals['iptables'],
                $this->parseIptablesCounters($mangleSave, $natSave, $enabledIfaces)
            );
        } catch (\Throwable) {
            // ignore and return best-effort static config fields
        }

        return $signals;
    }

    /**
     * @return array{
     *   tproxy_listen_addr: ?string,
     *   tproxy_listen_port: ?int,
     *   dns_listen_addr: ?string,
     *   dns_listen_port: ?int,
     *   delivery_inbound_type: ?string
     * }
     */
    private function readSingBoxRuntimeConfig(): array
    {
        $path = $this->paths->singBoxConfigPath();
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded['inbounds'] ?? null)) {
            return [];
        }

        $out = [
            'tproxy_listen_addr' => null,
            'tproxy_listen_port' => null,
            'dns_listen_addr' => null,
            'dns_listen_port' => null,
            'delivery_inbound_type' => null,
        ];

        foreach ($decoded['inbounds'] as $inbound) {
            if (! is_array($inbound)) {
                continue;
            }
            if (($inbound['tag'] ?? null) === ResolverService::TPROXY_INBOUND_TAG) {
                $out['tproxy_listen_addr'] = is_string($inbound['listen'] ?? null) ? $inbound['listen'] : null;
                $out['tproxy_listen_port'] = isset($inbound['listen_port']) ? (int) $inbound['listen_port'] : null;
                $out['delivery_inbound_type'] = is_string($inbound['type'] ?? null) ? $inbound['type'] : null;
            }
            if (($inbound['tag'] ?? null) === 'dns-in') {
                $out['dns_listen_addr'] = is_string($inbound['listen'] ?? null) ? $inbound['listen'] : null;
                $out['dns_listen_port'] = isset($inbound['listen_port']) ? (int) $inbound['listen_port'] : null;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function splitSections(string $output): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^__[A-Z0-9_]+__$/', $trimmed)) {
                $current = $trimmed;
                $sections[$current] = [];

                continue;
            }
            if ($current !== null) {
                $sections[$current][] = $line;
            }
        }

        return $sections;
    }

    /** @param  list<string>  $lines */
    private function hasListenerOnPort(array $lines, int $port): bool
    {
        foreach ($lines as $line) {
            if (preg_match('/[:.]'.preg_quote((string) $port, '/').'\b/', $line)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $lines */
    private function hasTproxyRule(array $lines): bool
    {
        foreach ($lines as $line) {
            if (str_contains($line, 'fwmark '.ResolverService::TPROXY_MARK)
                && str_contains($line, 'lookup '.(string) ResolverService::TPROXY_TABLE)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $lines */
    private function hasTproxyRoute(array $lines): bool
    {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, 'dev lo')) {
                continue;
            }
            if (str_contains($line, 'local 0.0.0.0/0')) {
                return true;
            }
            if (str_contains($line, 'local default')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $mangleLines
     * @param  list<string>  $natLines
     * @param  list<string>  $enabledIfaces
     * @return array{
     *   prerouting_rs_hits_by_iface: array<string,int>,
     *   divert_tcp_hits: int,
     *   divert_udp_hits: int,
     *   tproxy_fakeip_tcp_hits: int,
     *   tproxy_fakeip_udp_hits: int,
     *   tproxy_list_tcp_hits: int,
     *   tproxy_list_udp_hits: int,
     *   fakeip_rules_present: bool,
     *   nat_dns_redirect_hits: int
     * }
     */
    private function parseIptablesCounters(array $mangleLines, array $natLines, array $enabledIfaces): array
    {
        $out = [
            'prerouting_rs_hits_by_iface' => [],
            'divert_tcp_hits' => 0,
            'divert_udp_hits' => 0,
            'tproxy_fakeip_tcp_hits' => 0,
            'tproxy_fakeip_udp_hits' => 0,
            'tproxy_list_tcp_hits' => 0,
            'tproxy_list_udp_hits' => 0,
            'fakeip_rules_present' => false,
            'nat_dns_redirect_hits' => 0,
        ];
        foreach ($enabledIfaces as $iface) {
            $out['prerouting_rs_hits_by_iface'][$iface] = 0;
        }

        foreach ($mangleLines as $line) {
            if (! preg_match('/^\[(\d+):\d+\]\s+-A\s+(\S+)\s+(.*)$/', trim($line), $m)) {
                continue;
            }
            $packets = (int) $m[1];
            $chain = $m[2];
            $rule = $m[3];

            if ($chain === 'PREROUTING') {
                foreach ($enabledIfaces as $iface) {
                    if (str_contains($rule, '-i '.$iface)
                        && (str_contains($rule, '-j RS_'.$iface) || str_contains($rule, '-j RSNAT_'.$iface))) {
                        $out['prerouting_rs_hits_by_iface'][$iface] += $packets;
                    }
                }
                if (str_contains($rule, '-p tcp') && str_contains($rule, '-m socket') && str_contains($rule, '-j DIVERT')) {
                    $out['divert_tcp_hits'] += $packets;
                }
                if (str_contains($rule, '-p udp') && str_contains($rule, '-m socket') && str_contains($rule, '-j DIVERT')) {
                    $out['divert_udp_hits'] += $packets;
                }
            }

            // Legacy mangle TPROXY counters (pre-REDIRECT builds).
            if (str_starts_with($chain, 'RS_') && str_contains($rule, '-j TPROXY')) {
                $isFakeIp = str_contains($rule, '-d '.ResolverService::FAKEIP_CIDR);
                if ($isFakeIp) {
                    $out['fakeip_rules_present'] = true;
                }
                if (str_contains($rule, '--on-port '.(string) ResolverService::TPROXY_PORT)) {
                    if ($isFakeIp && str_contains($rule, '-p tcp')) {
                        $out['tproxy_fakeip_tcp_hits'] += $packets;
                    } elseif ($isFakeIp && str_contains($rule, '-p udp')) {
                        $out['tproxy_fakeip_udp_hits'] += $packets;
                    } elseif (str_contains($rule, '-p tcp')) {
                        $out['tproxy_list_tcp_hits'] += $packets;
                    } elseif (str_contains($rule, '-p udp')) {
                        $out['tproxy_list_udp_hits'] += $packets;
                    }
                }
            }
        }

        foreach ($natLines as $line) {
            if (! preg_match('/^\[(\d+):\d+\]\s+-A\s+(\S+)\s+(.*)$/', trim($line), $m)) {
                continue;
            }
            $packets = (int) $m[1];
            $chain = $m[2];
            $rule = $m[3];

            if ($chain === 'PREROUTING') {
                foreach ($enabledIfaces as $iface) {
                    if (str_contains($rule, '-i '.$iface) && str_contains($rule, '-j RSNAT_'.$iface)) {
                        $out['prerouting_rs_hits_by_iface'][$iface] += $packets;
                    }
                }
                if (str_contains($rule, '--dport 53') && str_contains($rule, '--to-ports '.(string) ResolverService::DNS_LISTEN_PORT)) {
                    $out['nat_dns_redirect_hits'] += $packets;
                }
            }

            // Current path: TCP FakeIP/list via nat REDIRECT into sing-box.
            if (str_starts_with($chain, 'RSNAT_') && str_contains($rule, '-j REDIRECT')) {
                $isFakeIp = str_contains($rule, '-d '.ResolverService::FAKEIP_CIDR);
                if ($isFakeIp) {
                    $out['fakeip_rules_present'] = true;
                }
                if (str_contains($rule, '--to-ports '.(string) ResolverService::TPROXY_PORT)
                    || str_contains($rule, '--to-ports='.(string) ResolverService::TPROXY_PORT)) {
                    if ($isFakeIp && str_contains($rule, '-p tcp')) {
                        $out['tproxy_fakeip_tcp_hits'] += $packets;
                    } elseif (str_contains($rule, '-p tcp')) {
                        $out['tproxy_list_tcp_hits'] += $packets;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array{ok: bool, address: ?string, detail: string}
     */
    private function probeDnsViaGateway(string $gateway, string $domain): array
    {
        $container = $this->awg->containerName();
        $script = <<<'SH'
set -e
GW="$1"
DOMAIN="$2"
SRC="10.66.66.250"
ip addr add "${SRC}/32" dev lo 2>/dev/null || true
# TCP dig: UDP replies can be dropped on lo-bound sources inside the container
OUT="$(dig +tcp +time=3 +tries=1 -b "$SRC" @"$GW" "$DOMAIN" A +short 2>/dev/null | head -1 || true)"
if [ -z "$OUT" ]; then
  OUT="$(dig +tcp +time=3 +tries=1 -b "$SRC" @127.0.0.1 "$DOMAIN" A +short 2>/dev/null | head -1 || true)"
fi
echo "$OUT"
SH;

        try {
            $r = $this->docker->exec(
                $container,
                ['sh', '-c', $script, '_', $gateway, $domain],
                timeout: 15,
            );
            $addr = trim($r->output());
            if ($addr === '' || ! filter_var($addr, FILTER_VALIDATE_IP)) {
                return [
                    'ok' => false,
                    'address' => null,
                    'detail' => __('resolver.diag_dns_dig_no_reply'),
                ];
            }

            return [
                'ok' => true,
                'address' => $addr,
                'detail' => $domain.' → '.$addr,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'address' => null,
                'detail' => $e->getMessage(),
            ];
        }
    }

}
