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

    public function test_store_result_and_status_persist_until_next_measurement(): void
    {
        $svc = $this->makeService();
        $svc->storeResult([
            'ok' => true,
            'outbound_tag' => 'conn_1',
            'connection_id' => 1,
            'node_key' => null,
            'ping_ms' => 42,
            'download_mbps' => 80.5,
            'upload_mbps' => 20.1,
            'download_bytes' => 1,
            'upload_bytes' => 1,
            'download_ms' => 100,
            'upload_ms' => 100,
            'error' => null,
        ]);

        $status = $svc->status();
        $this->assertFalse($status['running']);
        $this->assertNull($status['job']);
        $this->assertSame(42, $status['results']['by_key']['1']['ping_ms']);
        $this->assertSame(80.5, $status['results']['by_key']['1']['download_mbps']);
    }

    public function test_enqueue_connection_creates_queued_job_without_blocking(): void
    {
        $conn = ResolverConnection::query()->create([
            'name' => 'speed',
            'kind' => ResolverConnection::KIND_PROXY,
            'config_type' => 'json',
            'enabled' => true,
            'outbound' => [
                'type' => 'vless',
                'server' => 'x.example',
                'server_port' => 443,
                'uuid' => '00000000-0000-0000-0000-000000000001',
            ],
        ]);

        $svc = $this->makeService();
        $payload = $svc->enqueueConnection($conn, null);

        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['async']);
        $this->assertSame('queued', $payload['job']['status']);
        $this->assertSame((int) $conn->id, $payload['job']['connection_id']);

        $status = $svc->status();
        $this->assertTrue($status['running']);
        $this->assertSame($payload['job']['id'], $status['job']['id']);
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
