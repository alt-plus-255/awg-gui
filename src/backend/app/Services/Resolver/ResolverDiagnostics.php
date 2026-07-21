<?php

namespace App\Services\Resolver;

use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Support\Facades\Process;

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

        $dnsListening = false;
        $fakeipTun = false;
        $fakeipHits = 0;
        $tunUp = false;
        try {
            $r = Process::timeout(10)->run([
                'docker', 'exec', $container,
                'sh', '-c',
                'ss -ulnp | grep -q ":'.ResolverService::DNS_LISTEN_PORT.' " && echo DNS_OK; '
                .'ip link show '.ResolverService::TUN_IFACE.' >/dev/null 2>&1 && echo TUN_OK; '
                .'iptables -t mangle -L PREROUTING -n -v 2>/dev/null | grep -E "MARK|'.ResolverService::FAKEIP_CIDR.'" || true; '
                .'iptables -t nat -L PREROUTING -n -v 2>/dev/null | grep "dpt:53" || true',
            ]);
            $out = $r->output();
            $dnsListening = str_contains($out, 'DNS_OK');
            $tunUp = str_contains($out, 'TUN_OK');
            $fakeipTun = str_contains($out, 'MARK') && (str_contains($out, '198.18') || $tunUp);
            foreach (preg_split("/\r\n|\n|\r/", $out) ?: [] as $line) {
                if (! str_contains($line, 'MARK') && ! str_contains($line, '198.18')) {
                    continue;
                }
                if (preg_match('/^\s*(\d+)\s+/', $line, $m)) {
                    $fakeipHits += (int) $m[1];
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        $checks[] = [
            'id' => 'dns_listen',
            'ok' => $dnsListening,
            'label' => 'DNS listen :'.ResolverService::DNS_LISTEN_PORT,
            'detail' => $dnsListening ? __('resolver.diag_dns_listening', ['port' => ResolverService::DNS_LISTEN_PORT]) : __('resolver.diag_dns_not_listening', ['port' => ResolverService::DNS_LISTEN_PORT]),
        ];
        $checks[] = [
            'id' => 'fakeip_tun',
            'ok' => $tunUp && $fakeipTun,
            'label' => 'FakeIP → TUN '.ResolverService::TUN_IFACE,
            'detail' => $tunUp
                ? __('resolver.diag_tun_up_marks', ['hits' => $fakeipHits])
                : __('resolver.diag_tun_down', ['iface' => ResolverService::TUN_IFACE]),
        ];
        if ($tunUp && $fakeipHits === 0) {
            $hints[] = __('resolver.diag_no_fakeip_traffic');
        }

        $clashOk = $this->clash->waitForClashApi(5, 150);
        $checks[] = [
            'id' => 'clash_api',
            'ok' => $clashOk,
            'label' => 'Clash API',
            'detail' => $clashOk ? __('resolver.diag_available') : __('resolver.diag_unavailable'),
        ];

        $clashConns = 0;
        if ($clashOk) {
            $connResp = $this->clash->clashApiRequest('/connections', [], 5);
            if (is_array($connResp['body']['connections'] ?? null)) {
                $clashConns = count($connResp['body']['connections']);
            }
        }
        if ($fakeipHits > 20 && $clashConns === 0) {
            $checks[] = [
                'id' => 'tun_delivery',
                'ok' => false,
                'label' => __('resolver.diag_fakeip_delivery'),
                'detail' => "mark_hits≈{$fakeipHits}, clash_connections=0",
            ];
            $hints[] = __('resolver.diag_marked_no_sessions', ['table' => ResolverService::TUN_TABLE, 'iface' => ResolverService::TUN_IFACE]);
        } elseif ($clashConns > 0) {
            $checks[] = [
                'id' => 'tun_delivery',
                'ok' => true,
                'label' => __('resolver.diag_fakeip_delivery'),
                'detail' => __('resolver.diag_active_clash_connections', ['count' => $clashConns]),
            ];
        }

        $enabled = $resolver->enabledServerConfigs();
        $enabledTags = $resolver->collectCommunityTagsFromConfigs($enabled);
        $dnsSamples = [];

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
            $checks[] = [
                'id' => 'merged_cfg_'.$cfg->id,
                'ok' => $ok,
                'label' => 'Merged domains «'.$cfg->name.'»',
                'detail' => $ok
                    ? ('merged_cfg_'.$cfg->id.'.json · '.number_format((int) filesize($mergedPath)).' B')
                    : __('resolver.diag_merged_missing', ['id' => $cfg->id]),
            ];

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
            'dns_samples' => $dnsSamples,
            'updated_at' => now()->toIso8601String(),
        ];
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
            $r = Process::timeout(15)->run([
                'docker', 'exec', $container,
                'sh', '-c', $script, '_', $gateway, $domain,
            ]);
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
