<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\ResolverConnection;
use App\Models\Setting;
use App\Services\Resolver\ResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolverSingBoxTproxyConfigTest extends TestCase
{
    use RefreshDatabase;

    private string $awgDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awgDir = sys_get_temp_dir().'/awg-gui-tproxy-test-'.uniqid('', true);
        mkdir($this->awgDir.'/rulesets', 0755, true);
        putenv('AWG_CONFIG_DIR='.$this->awgDir);
        $_ENV['AWG_CONFIG_DIR'] = $this->awgDir;
        $_SERVER['AWG_CONFIG_DIR'] = $this->awgDir;

        Setting::setValue('telegram_proxies', '[]');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->awgDir);
        parent::tearDown();
    }

    public function test_build_sing_box_config_uses_tproxy_and_isolates_multi_config(): void
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -6);
        $ifaceA = 'tpa'.$suffix;
        $ifaceB = 'tpb'.$suffix;
        $portA = 40000 + (hexdec(substr($suffix, 0, 4)) % 10000);
        $portB = $portA === 49999 ? 49998 : $portA + 1;

        $connA = ResolverConnection::query()->create([
            'name' => 'Exit A '.$suffix,
            'kind' => ResolverConnection::KIND_PROXY,
            'config_type' => 'json',
            'enabled' => true,
            'outbound' => [
                'type' => 'socks',
                'server' => '127.0.0.1',
                'server_port' => 1080,
            ],
        ]);
        $connB = ResolverConnection::query()->create([
            'name' => 'Exit B '.$suffix,
            'kind' => ResolverConnection::KIND_PROXY,
            'config_type' => 'json',
            'enabled' => true,
            'outbound' => [
                'type' => 'socks',
                'server' => '127.0.0.1',
                'server_port' => 1081,
            ],
        ]);

        $cfgA = AwgConfig::query()->create([
            'name' => 'Server A '.$suffix,
            'type' => 'server',
            'iface' => $ifaceA,
            'listen_port' => $portA,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1',
            'server_private_key' => 'privA',
            'server_public_key' => 'pubA',
            'enabled' => true,
            'resolver_enabled' => true,
            'resolver_reject_quic' => true,
            'community_lists' => [],
            'user_domains' => ['youtube.com'],
            'user_subnets' => [],
            'connection_id' => $connA->id,
        ]);
        $cfgB = AwgConfig::query()->create([
            'name' => 'Server B '.$suffix,
            'type' => 'server',
            'iface' => $ifaceB,
            'listen_port' => $portB,
            'internal_subnet' => '10.77.77.0/24',
            'server_address' => '10.77.77.1',
            'server_private_key' => 'privB',
            'server_public_key' => 'pubB',
            'enabled' => true,
            'resolver_enabled' => true,
            'resolver_reject_quic' => false,
            'community_lists' => [],
            'user_domains' => ['youtube.com'],
            'user_subnets' => [],
            'connection_id' => $connB->id,
        ]);

        try {
            $cfgA->load('resolverConnection');
            $cfgB->load('resolverConnection');

            $sb = app(ResolverService::class)->buildSingBoxConfig([$cfgA, $cfgB], forceSyncLists: false);

            $inboundTypes = array_column($sb['inbounds'], 'type');
            $this->assertContains('redirect', $inboundTypes);
            $this->assertContains('tproxy', $inboundTypes);
            $this->assertNotContains('tun', $inboundTypes);

            $redir = collect($sb['inbounds'])->firstWhere('tag', ResolverService::TPROXY_INBOUND_TAG);
            $this->assertNotNull($redir);
            $this->assertSame('redirect', $redir['type']);
            $this->assertSame(ResolverService::TPROXY_LISTEN, $redir['listen']);
            $this->assertSame(ResolverService::TPROXY_PORT, $redir['listen_port']);
            $this->assertTrue($redir['tcp_fast_open'] ?? false);
            $this->assertTrue($redir['sniff_override_destination'] ?? false);

            $udp = collect($sb['inbounds'])->firstWhere('tag', ResolverService::UDP_TPROXY_INBOUND_TAG);
            $this->assertNotNull($udp);
            $this->assertSame('tproxy', $udp['type']);
            $this->assertSame(ResolverService::UDP_TPROXY_PORT, $udp['listen_port']);
            $this->assertSame('udp', $udp['network'] ?? null);
            $this->assertTrue($udp['udp_fragment'] ?? false);
            $this->assertTrue($udp['sniff_override_destination'] ?? false);

            $dnsIn = collect($sb['inbounds'])->firstWhere('tag', 'dns-in');
            $this->assertSame('direct', $dnsIn['type']);
            $this->assertSame(ResolverService::DNS_LISTEN_PORT, $dnsIn['listen_port']);
            $this->assertArrayNotHasKey('sniff', $dnsIn);
            $this->assertArrayNotHasKey('sniff_override_destination', $dnsIn);

            $this->assertFalse($sb['route']['auto_detect_interface']);
            $this->assertNotSame('', $sb['route']['default_interface']);
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+$/', $sb['route']['default_interface']);
            $this->assertArrayNotHasKey('exclude_interface', $sb['route']);
            $this->assertSame('direct', $sb['route']['final']);
            $this->assertTrue($sb['dns']['independent_cache'] ?? false);

            $sniff = $sb['route']['rules'][0] ?? [];
            $this->assertSame('sniff', $sniff['action'] ?? null);
            $this->assertSame('300ms', $sniff['timeout'] ?? null);
            $this->assertContains(ResolverService::TPROXY_INBOUND_TAG, $sniff['inbound'] ?? []);
            $this->assertContains(ResolverService::UDP_TPROXY_INBOUND_TAG, $sniff['inbound'] ?? []);

            foreach ($sb['inbounds'] as $inbound) {
                $this->assertArrayNotHasKey('sniff', $inbound);
                $this->assertArrayNotHasKey('domain_strategy', $inbound);
                $tag = $inbound['tag'] ?? '';
                if ($tag === ResolverService::TPROXY_INBOUND_TAG || $tag === ResolverService::UDP_TPROXY_INBOUND_TAG) {
                    $this->assertTrue($inbound['sniff_override_destination'] ?? false);
                } else {
                    $this->assertArrayNotHasKey('sniff_override_destination', $inbound);
                }
            }
            foreach ($sb['dns']['servers'] as $server) {
                $this->assertArrayHasKey('type', $server);
                $this->assertArrayNotHasKey('address', $server);
            }
            foreach ($sb['dns']['rules'] as $rule) {
                $this->assertArrayHasKey('action', $rule);
            }
            foreach ($sb['route']['rules'] as $rule) {
                $this->assertArrayHasKey('action', $rule);
            }
            foreach ($sb['outbounds'] as $outbound) {
                $this->assertNotSame('wireguard', $outbound['type'] ?? null);
                $this->assertNotSame('block', $outbound['type'] ?? null);
                $this->assertNotSame('dns', $outbound['type'] ?? null);
                $this->assertArrayNotHasKey('override_address', $outbound);
                $this->assertArrayNotHasKey('override_port', $outbound);
            }

            $domainRoutes = array_values(array_filter(
                $sb['route']['rules'],
                fn (array $r) => isset($r['rule_set'], $r['outbound'], $r['source_ip_cidr'], $r['inbound'])
                    && in_array(ResolverService::TPROXY_INBOUND_TAG, $r['inbound'], true)
                    && in_array(ResolverService::UDP_TPROXY_INBOUND_TAG, $r['inbound'], true)
                    && ! str_ends_with($r['rule_set'][0] ?? '', '_ip')
            ));
            $this->assertCount(2, $domainRoutes);

            $bySource = [];
            foreach ($domainRoutes as $r) {
                $bySource[$r['source_ip_cidr'][0]] = $r['outbound'];
            }
            $this->assertSame('conn_'.$connA->id, $bySource['10.66.66.0/24']);
            $this->assertSame('conn_'.$connB->id, $bySource['10.77.77.0/24']);

            $quicRules = array_values(array_filter(
                $sb['route']['rules'],
                fn (array $r) => ($r['protocol'] ?? null) === 'quic' && ($r['action'] ?? null) === 'reject'
            ));
            $this->assertCount(1, $quicRules);
            $this->assertSame(['10.66.66.0/24'], $quicRules[0]['source_ip_cidr']);
            $this->assertSame([ResolverService::UDP_TPROXY_INBOUND_TAG], $quicRules[0]['inbound']);

            $this->assertSame(['HTTPS'], $sb['dns']['rules'][0]['query_type'] ?? null);
            $this->assertSame('reject', $sb['dns']['rules'][0]['action'] ?? null);
            $this->assertContains('use-application-dns.net', $sb['dns']['rules'][1]['domain_suffix'] ?? []);

            $this->assertTrue($sb['experimental']['cache_file']['store_fakeip'] ?? false);
            $this->assertSame(60, ResolverService::FAKEIP_REWRITE_TTL);

            $fakeipDns = array_values(array_filter(
                $sb['dns']['rules'],
                fn (array $r) => ($r['server'] ?? null) === 'fakeip'
            ));
            $this->assertNotEmpty($fakeipDns);
            foreach ($fakeipDns as $rule) {
                $this->assertSame(ResolverService::FAKEIP_REWRITE_TTL, $rule['rewrite_ttl'] ?? null);
                $this->assertArrayHasKey('source_ip_cidr', $rule);
            }
        } finally {
            $cfgA->delete();
            $cfgB->delete();
            $connA->delete();
            $connB->delete();
        }
    }

    public function test_reject_quic_keeps_udp_tproxy_and_adds_protocol_reject(): void
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -6);
        $iface = 'tpq'.$suffix;
        $port = 41000 + (hexdec(substr($suffix, 0, 4)) % 9000);

        $conn = ResolverConnection::query()->create([
            'name' => 'Exit Q '.$suffix,
            'kind' => ResolverConnection::KIND_PROXY,
            'config_type' => 'json',
            'enabled' => true,
            'outbound' => [
                'type' => 'socks',
                'server' => '127.0.0.1',
                'server_port' => 1080,
            ],
        ]);

        $cfg = AwgConfig::query()->create([
            'name' => 'Server Q '.$suffix,
            'type' => 'server',
            'iface' => $iface,
            'listen_port' => $port,
            'internal_subnet' => '10.88.88.0/24',
            'server_address' => '10.88.88.1',
            'server_private_key' => 'privQ',
            'server_public_key' => 'pubQ',
            'enabled' => true,
            'resolver_enabled' => true,
            'resolver_reject_quic' => true,
            'community_lists' => [],
            'user_domains' => ['youtube.com'],
            'user_subnets' => [],
            'connection_id' => $conn->id,
        ]);

        try {
            $cfg->load('resolverConnection');
            $sb = app(ResolverService::class)->buildSingBoxConfig([$cfg], forceSyncLists: false);

            $this->assertContains('redirect', array_column($sb['inbounds'], 'type'));
            $this->assertContains('tproxy', array_column($sb['inbounds'], 'type'));
            $this->assertNotNull(collect($sb['inbounds'])->firstWhere('tag', ResolverService::UDP_TPROXY_INBOUND_TAG));

            $quicRules = array_values(array_filter(
                $sb['route']['rules'],
                fn (array $r) => ($r['protocol'] ?? null) === 'quic' && ($r['action'] ?? null) === 'reject'
            ));
            $this->assertCount(1, $quicRules);
            $this->assertSame(['10.88.88.0/24'], $quicRules[0]['source_ip_cidr']);
            $this->assertSame([ResolverService::UDP_TPROXY_INBOUND_TAG], $quicRules[0]['inbound']);
        } finally {
            $cfg->delete();
            $conn->delete();
        }
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
