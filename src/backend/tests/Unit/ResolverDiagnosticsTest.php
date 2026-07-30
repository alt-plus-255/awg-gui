<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\ClashApiClient;
use App\Services\Resolver\ResolverDiagnostics;
use App\Services\Resolver\ResolverPaths;
use App\Services\Resolver\ResolverService;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ResolverDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private string $awgDir;

    public function test_has_tproxy_route_accepts_local_default_dev_lo_format(): void
    {
        $svc = app(ResolverDiagnostics::class);
        $ref = new \ReflectionMethod($svc, 'hasTproxyRoute');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($svc, [
            'local default dev lo scope host',
        ]));
    }

    public function test_has_tproxy_route_accepts_local_zero_route_format(): void
    {
        $svc = app(ResolverDiagnostics::class);
        $ref = new \ReflectionMethod($svc, 'hasTproxyRoute');
        $ref->setAccessible(true);

        $this->assertTrue($ref->invoke($svc, [
            'local 0.0.0.0/0 dev lo table 100',
        ]));
    }

    public function test_has_tproxy_route_rejects_unrelated_lo_routes(): void
    {
        $svc = app(ResolverDiagnostics::class);
        $ref = new \ReflectionMethod($svc, 'hasTproxyRoute');
        $ref->setAccessible(true);

        $this->assertFalse($ref->invoke($svc, [
            'broadcast 10.66.66.255 dev lo proto kernel scope link src 10.66.66.1',
        ]));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->awgDir = sys_get_temp_dir().'/awg-gui-diag-test-'.uniqid('', true);
        mkdir($this->awgDir.'/rulesets', 0755, true);
        putenv('AWG_CONFIG_DIR='.$this->awgDir);
        $_ENV['AWG_CONFIG_DIR'] = $this->awgDir;
        $_SERVER['AWG_CONFIG_DIR'] = $this->awgDir;
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->awgDir);
        parent::tearDown();
    }

    public function test_diagnose_separates_rs_chain_hits_from_fakeip_tproxy_hits(): void
    {
        $config = AwgConfig::query()->create([
            'name' => 'Resolver Test',
            'type' => 'server',
            'iface' => 'awg0',
            'listen_port' => 51820,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1',
            'server_private_key' => 'priv',
            'server_public_key' => 'pub',
            'enabled' => true,
            'resolver_enabled' => true,
            'community_lists' => [],
            'user_domains' => [],
            'user_subnets' => [],
        ]);

        file_put_contents($this->awgDir.'/sing-box.json', json_encode([
            'inbounds' => [
                [
                    'type' => 'direct',
                    'tag' => 'dns-in',
                    'listen' => '0.0.0.0',
                    'listen_port' => 53,
                ],
                [
                    'type' => 'redirect',
                    'tag' => ResolverService::TPROXY_INBOUND_TAG,
                    'listen' => ResolverService::TPROXY_LISTEN,
                    'listen_port' => ResolverService::TPROXY_PORT,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents($this->awgDir.'/rulesets/merged_cfg_'.$config->id.'.json', "{\"version\":1}\n");

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('containerName')->andReturn('awggui-awg');
        $awg->shouldReceive('configDir')->andReturn($this->awgDir);

        $docker = Mockery::mock(DockerRuntime::class);
        $docker->shouldReceive('exec')
            ->once()
            ->andReturn($this->processResult('yes'));
        $docker->shouldReceive('exec')
            ->once()
            ->andReturn($this->processResult(<<<'OUT'
__SS_UDP__
UNCONN 0 0 0.0.0.0:53 0.0.0.0:* users:(("sing-box",pid=1,fd=3))
__SS_TCP__
LISTEN 0 4096 0.0.0.0:1602 0.0.0.0:* users:(("sing-box",pid=1,fd=5))
__IP_RULE__
0:      from all lookup local
__IP_ROUTE_100__
__MANGLE_SAVE__
*mangle
COMMIT
__NAT_SAVE__
*nat
[42:2048] -A PREROUTING -i awg0 -j RSNAT_awg0
[7:420] -A PREROUTING -i awg0 -p udp -m udp --dport 53 -j REDIRECT --to-ports 53
[0:0] -A RSNAT_awg0 -d 198.18.0.0/15 -p tcp -j REDIRECT --to-ports 1602
[5:300] -A RSNAT_awg0 -d 104.16.0.0/12 -p tcp -j REDIRECT --to-ports 1602
COMMIT
OUT
            ));

        $clash = Mockery::mock(ClashApiClient::class);
        $clash->shouldReceive('waitForClashApi')->once()->with(5, 150)->andReturn(true);
        $clash->shouldReceive('clashApiRequest')
            ->once()
            ->with('/connections', [], 5)
            ->andReturn([
                'ok' => true,
                'status' => 200,
                'body' => ['connections' => []],
                'raw' => '{"connections":[]}',
                'error' => null,
            ]);

        $resolver = Mockery::mock(ResolverService::class);
        $resolver->shouldReceive('isSingBoxRunning')->once()->andReturn(true);
        $resolver->shouldReceive('enabledServerConfigs')->once()->andReturn([$config]);
        $resolver->shouldReceive('collectCommunityTagsFromConfigs')->once()->with([$config])->andReturn([]);
        $resolver->shouldReceive('gatewayIp')->zeroOrMoreTimes()->andReturn('10.66.66.1');

        $diag = new ResolverDiagnostics($awg, $docker, new ResolverPaths($awg), $clash);
        $result = $diag->diagnose($resolver);

        $this->assertSame(42, $result['details']['iptables']['prerouting_rs_hits_by_iface']['awg0']);
        $this->assertSame(0, $result['details']['iptables']['tproxy_fakeip_tcp_hits']);
        $this->assertSame(0, $result['details']['iptables']['tproxy_fakeip_udp_hits']);
        $this->assertSame(5, $result['details']['iptables']['tproxy_list_tcp_hits']);
        $this->assertSame(7, $result['details']['iptables']['nat_dns_redirect_hits']);
        $this->assertSame(0, $result['details']['clash']['connections_current']);
        $this->assertSame(ResolverService::TPROXY_LISTEN, $result['details']['config']['tproxy_listen_addr']);
        $this->assertSame('redirect', $result['details']['config']['delivery_inbound_type']);

        $policy = collect($result['checks'])->firstWhere('id', 'tproxy_policy');
        $this->assertNotNull($policy);
        $this->assertTrue($policy['ok']);

        $delivery = collect($result['checks'])->firstWhere('id', 'tproxy_delivery');
        $this->assertNotNull($delivery);
        $this->assertFalse($delivery['ok']);
        $this->assertStringContainsString('rs_hits=42', $delivery['detail']);
        $this->assertStringContainsString('fakeip_hits=0', $delivery['detail']);
    }

    private function processResult(string $output): ProcessResult
    {
        $result = Mockery::mock(ProcessResult::class);
        $result->shouldReceive('output')->andReturn($output);
        $result->shouldReceive('errorOutput')->andReturn('');
        $result->shouldReceive('successful')->andReturn(true);

        return $result;
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
