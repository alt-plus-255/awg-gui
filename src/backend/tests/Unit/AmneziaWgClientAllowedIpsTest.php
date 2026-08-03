<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\Versions\AwgVersionRegistry;
use App\Services\Docker\DockerRuntime;
use PHPUnit\Framework\TestCase;

class AmneziaWgClientAllowedIpsTest extends TestCase
{
    private function service(): AmneziaWgService
    {
        $docker = $this->createMock(DockerRuntime::class);

        return new AmneziaWgService($docker, new AwgVersionRegistry);
    }

    public function test_empty_extras_uses_config_client_allowed_ips(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => false,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1/24',
            'client_allowed_ips' => '0.0.0.0/0, ::/0',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => [],
        ]);

        $this->assertSame(
            ['0.0.0.0/0', '::/0'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_extras_without_resolver_use_internal_subnet_plus_cidrs(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => false,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1/24',
            'client_allowed_ips' => '0.0.0.0/0, ::/0',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['192.168.1.13/32', '10.0.0.0/8'],
        ]);

        $this->assertSame(
            ['10.66.66.0/24', '192.168.1.13/32', '10.0.0.0/8'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_falls_back_to_canonical_server_address_when_subnet_missing(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => false,
            'internal_subnet' => '',
            'server_address' => '10.66.66.1/24',
            'client_allowed_ips' => '0.0.0.0/0, ::/0',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['192.168.1.13/32'],
        ]);

        $this->assertSame(
            ['10.66.66.0/24', '192.168.1.13/32'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_extras_with_resolver_force_full_tunnel(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => true,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1/24',
            'client_allowed_ips' => '0.0.0.0/0, ::/0',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['192.168.1.13/32'],
        ]);

        $this->assertSame(
            ['0.0.0.0/0', '::/0'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_full_tunnel_cidrs_in_extras_are_filtered(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => false,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1/24',
            'client_allowed_ips' => '0.0.0.0/0, ::/0',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['0.0.0.0/0', '192.168.1.13/32', '::/0'],
        ]);

        $this->assertSame(
            ['10.66.66.0/24', '192.168.1.13/32'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_blank_and_whitespace_extras_fall_back_to_config(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => false,
            'internal_subnet' => '10.66.66.0/24',
            'server_address' => '10.66.66.1/24',
            'client_allowed_ips' => '0.0.0.0/0, ::/0',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['', '  '],
        ]);

        $this->assertSame(
            ['0.0.0.0/0', '::/0'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_canonical_network_cidr_clears_host_bits(): void
    {
        $service = $this->service();

        $this->assertSame('10.66.66.0/24', $service->canonicalNetworkCidr('10.66.66.1/24'));
        $this->assertSame('192.168.1.13/32', $service->canonicalNetworkCidr('192.168.1.13/32'));
        $this->assertSame('10.0.0.0/8', $service->canonicalNetworkCidr('10.1.2.3/8'));
    }

    public function test_server_peer_allowed_ips_exclude_extras_on_server_type(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'server',
            'resolver_enabled' => false,
            'internal_subnet' => '10.66.66.0/24',
        ]);
        $membership = new AwgConfigPeer([
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['192.168.10.5/32', '10.0.0.0/8'],
        ]);
        $membership->setRelation('config', $config);

        $this->assertSame(
            ['10.66.66.2/32'],
            $service->serverPeerAllowedIps($membership)
        );
        $this->assertSame(
            ['10.66.66.0/24', '192.168.10.5/32', '10.0.0.0/8'],
            $service->clientAllowedIps($config, $membership)
        );
    }

    public function test_server_peer_allowed_ips_include_extras_on_virtual_network(): void
    {
        $service = $this->service();
        $config = new AwgConfig([
            'type' => 'virtual_network',
            'vn_policy' => 'allow_all',
            'internal_subnet' => '10.66.66.0/24',
        ]);
        $membership = new AwgConfigPeer([
            'id' => 1,
            'address' => '10.66.66.2/32',
            'extra_allowed_ips' => ['192.168.10.0/24'],
        ]);
        $membership->setRelation('config', $config);

        $this->assertSame(
            ['10.66.66.2/32', '192.168.10.0/24'],
            $service->serverPeerAllowedIps($membership)
        );
    }
}
