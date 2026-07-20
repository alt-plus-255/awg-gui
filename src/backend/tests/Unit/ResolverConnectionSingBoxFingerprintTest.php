<?php

namespace Tests\Unit;

use App\Models\ResolverConnection;
use App\Services\Resolver\ResolverConnectionSingBoxFingerprint;
use App\Services\Resolver\SingBoxOutboundParser;
use Tests\TestCase;

class ResolverConnectionSingBoxFingerprintTest extends TestCase
{
    private ResolverConnectionSingBoxFingerprint $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fingerprint = new ResolverConnectionSingBoxFingerprint(new SingBoxOutboundParser);
    }

    public function test_nodes_equal_ignores_non_outbound_fields(): void
    {
        $a = [
            ['key' => 'n1', 'name' => 'Old name', 'outbound' => ['type' => 'vless', 'server' => 'a.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001']],
        ];
        $b = [
            ['key' => 'n1', 'name' => 'New name', 'outbound' => ['type' => 'vless', 'server' => 'a.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001']],
        ];

        $this->assertTrue($this->fingerprint->nodesEqual($a, $b));
    }

    public function test_nodes_not_equal_when_outbound_changes(): void
    {
        $a = [
            ['key' => 'n1', 'outbound' => ['type' => 'vless', 'server' => 'a.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001']],
        ];
        $b = [
            ['key' => 'n1', 'outbound' => ['type' => 'vless', 'server' => 'b.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000001']],
        ];

        $this->assertFalse($this->fingerprint->nodesEqual($a, $b));
    }

    public function test_hash_unchanged_for_metadata_only_changes(): void
    {
        $conn = new ResolverConnection([
            'id' => 3,
            'kind' => ResolverConnection::KIND_SUBSCRIPTION,
            'subscription_mode' => ResolverConnection::MODE_URLTEST,
            'enabled' => true,
            'ping_check_interval_min' => 5,
            'subscription_nodes' => [
                ['key' => 'n1', 'outbound' => ['type' => 'ss', 'server' => 'a.example', 'server_port' => 8388, 'password' => 'x', 'method' => 'aes-256-gcm']],
            ],
        ]);

        $before = $this->fingerprint->hash($conn);
        $conn->name = 'Renamed';
        $conn->comment = 'new comment';
        $conn->subscription_fetched_at = now();

        $this->assertSame($before, $this->fingerprint->hash($conn));
    }

    public function test_hash_changes_when_selected_node_changes(): void
    {
        $nodes = [
            ['key' => 'n1', 'outbound' => ['type' => 'ss', 'server' => 'a.example', 'server_port' => 8388, 'password' => 'x', 'method' => 'aes-256-gcm']],
            ['key' => 'n2', 'outbound' => ['type' => 'ss', 'server' => 'b.example', 'server_port' => 8388, 'password' => 'y', 'method' => 'aes-256-gcm']],
        ];

        $conn = new ResolverConnection([
            'id' => 4,
            'kind' => ResolverConnection::KIND_SUBSCRIPTION,
            'subscription_mode' => ResolverConnection::MODE_SINGLE,
            'subscription_selected' => 'n1',
            'enabled' => true,
            'subscription_nodes' => $nodes,
            'outbound' => $nodes[0]['outbound'],
        ]);

        $before = $this->fingerprint->hash($conn);
        $conn->subscription_selected = 'n2';
        $conn->outbound = $nodes[1]['outbound'];

        $this->assertNotSame($before, $this->fingerprint->hash($conn));
    }
}
