<?php

namespace Tests\Unit;

use App\Models\ResolverConnection;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\Docker\DockerRuntime;
use App\Services\Resolver\ConnectionOutboundBuilder;
use App\Services\Resolver\ResolverPaths;
use App\Services\Resolver\ResolverService;
use App\Services\Resolver\SingBoxOutboundParser;
use App\Services\Resolver\SpeedTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SpeedTestServiceTest extends TestCase
{
    use RefreshDatabase;
    public function test_build_config_has_mixed_inbound_and_speed_clash_api(): void
    {
        $svc = $this->makeService();
        $config = $svc->buildConfig([
            ['type' => 'direct', 'tag' => 'direct'],
            ['type' => 'vless', 'tag' => 'conn_1', 'server' => 'x.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001'],
        ], 'conn_1');

        $this->assertSame('mixed', $config['inbounds'][0]['type']);
        $this->assertSame(ResolverService::SPEED_MIXED_PORT, $config['inbounds'][0]['listen_port']);
        $this->assertSame(ResolverService::CLASH_SPEED_API_ADDR, $config['experimental']['clash_api']['external_controller']);
        $this->assertSame('conn_1', $config['route']['final']);
        $this->assertSame(SpeedTestService::MIXED_TAG, $config['route']['rules'][0]['inbound'][0]);
        $this->assertFalse($config['route']['auto_detect_interface']);
        $this->assertArrayNotHasKey('exclude_interface', $config['route']);
    }

    public function test_build_outbounds_for_urltest_parent_includes_children(): void
    {
        $conn = new ResolverConnection([
            'id' => 3,
            'kind' => ResolverConnection::KIND_SUBSCRIPTION,
            'subscription_mode' => ResolverConnection::MODE_URLTEST,
            'enabled' => true,
            'subscription_nodes' => [
                [
                    'key' => 'n1',
                    'outbound' => ['type' => 'vless', 'server' => 'a.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001'],
                ],
                [
                    'key' => 'n2',
                    'outbound' => ['type' => 'ss', 'server' => 'b.example', 'server_port' => 8388, 'password' => 'x', 'method' => 'aes-256-gcm'],
                ],
            ],
        ]);

        [$tag, $outbounds] = $this->makeService()->buildOutbounds($conn, null);
        $this->assertSame('conn_3', $tag);
        $tags = array_column($outbounds, 'tag');
        $this->assertContains('direct', $tags);
        $this->assertContains('conn_3', $tags);
        $this->assertContains('conn_3_1', $tags);
        $this->assertContains('conn_3_2', $tags);
    }

    public function test_build_outbounds_for_single_node_key(): void
    {
        $conn = new ResolverConnection([
            'id' => 3,
            'kind' => ResolverConnection::KIND_SUBSCRIPTION,
            'subscription_mode' => ResolverConnection::MODE_URLTEST,
            'enabled' => true,
            'subscription_nodes' => [
                [
                    'key' => 'n1',
                    'outbound' => ['type' => 'vless', 'server' => 'a.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001'],
                ],
                [
                    'key' => 'n2',
                    'outbound' => ['type' => 'ss', 'server' => 'b.example', 'server_port' => 8388, 'password' => 'x', 'method' => 'aes-256-gcm'],
                ],
            ],
        ]);

        [$tag, $outbounds] = $this->makeService()->buildOutbounds($conn, 'n2');
        $this->assertSame('conn_3_2', $tag);
        $tags = array_column($outbounds, 'tag');
        $this->assertSame(['direct', 'conn_3_2'], $tags);
        $this->assertSame('shadowsocks', $outbounds[1]['type']);
    }

    private function makeService(): SpeedTestService
    {
        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('configDir')->andReturn('/tmp/awg-speed-test')->byDefault();
        $docker = Mockery::mock(DockerRuntime::class);
        $paths = new ResolverPaths($awg);
        $parser = new SingBoxOutboundParser;
        $builder = new ConnectionOutboundBuilder($parser);

        return new SpeedTestService($awg, $docker, $paths, $builder, $parser);
    }
}
