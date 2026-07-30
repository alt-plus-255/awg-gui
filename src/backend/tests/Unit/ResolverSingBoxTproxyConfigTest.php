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
            $this->assertContains('tproxy', $inboundTypes);
            $this->assertNotContains('tun', $inboundTypes);

            $tproxy = collect($sb['inbounds'])->firstWhere('type', 'tproxy');
            $this->assertSame(ResolverService::TPROXY_INBOUND_TAG, $tproxy['tag']);
            $this->assertSame(ResolverService::TPROXY_LISTEN, $tproxy['listen']);
            $this->assertSame(ResolverService::TPROXY_PORT, $tproxy['listen_port']);
            $this->assertTrue($tproxy['tcp_fast_open']);
            $this->assertTrue($tproxy['udp_fragment']);

            $dnsIn = collect($sb['inbounds'])->firstWhere('tag', 'dns-in');
            $this->assertSame('direct', $dnsIn['type']);
            $this->assertSame(ResolverService::DNS_LISTEN_PORT, $dnsIn['listen_port']);
            $this->assertArrayNotHasKey('sniff', $dnsIn);

            $this->assertFalse($sb['route']['auto_detect_interface']);
            $this->assertSame(ResolverService::EGRESS_INTERFACE, $sb['route']['default_interface']);
            $this->assertContains($ifaceA, $sb['route']['exclude_interface']);
            $this->assertContains($ifaceB, $sb['route']['exclude_interface']);
            $this->assertContains(ResolverService::TUN_IFACE, $sb['route']['exclude_interface']);
            $this->assertSame('direct', $sb['route']['final']);
            $this->assertNotContains('tun', array_column($sb['inbounds'], 'type'));

            $domainRoutes = array_values(array_filter(
                $sb['route']['rules'],
                fn (array $r) => isset($r['rule_set'], $r['outbound'], $r['source_ip_cidr'])
                    && ($r['inbound'][0] ?? null) === ResolverService::TPROXY_INBOUND_TAG
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
            $this->assertSame([ResolverService::TPROXY_INBOUND_TAG], $quicRules[0]['inbound']);

            $this->assertSame(['HTTPS'], $sb['dns']['rules'][0]['query_type'] ?? null);
            $this->assertSame('reject', $sb['dns']['rules'][0]['action'] ?? null);
            $this->assertContains('use-application-dns.net', $sb['dns']['rules'][1]['domain_suffix'] ?? []);

            $fakeipDns = array_values(array_filter(
                $sb['dns']['rules'],
                fn (array $r) => ($r['server'] ?? null) === 'fakeip'
            ));
            $this->assertNotEmpty($fakeipDns);
            foreach ($fakeipDns as $rule) {
                $this->assertSame(60, $rule['rewrite_ttl'] ?? null);
                $this->assertArrayHasKey('source_ip_cidr', $rule);
            }
        } finally {
            $cfgA->delete();
            $cfgB->delete();
            $connA->delete();
            $connB->delete();
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
