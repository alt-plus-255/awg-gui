<?php

namespace Tests\Unit;

use App\Models\ResolverConnection;
use App\Services\Resolver\ConnectionOutboundBuilder;
use App\Services\Resolver\SingBoxOutboundParser;
use Tests\TestCase;

class ConnectionOutboundBuilderTest extends TestCase
{
    public function test_urltest_builds_child_tags_matching_connection_model(): void
    {
        $conn = new ResolverConnection([
            'id' => 5,
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

        $builder = new ConnectionOutboundBuilder(new SingBoxOutboundParser);
        $built = $builder->buildForConnections([$conn]);

        $tags = array_map(fn ($ob) => $ob['tag'] ?? null, $built['outbounds']);
        $this->assertContains('conn_5_1', $tags);
        $this->assertContains('conn_5_2', $tags);
        $this->assertContains('conn_5', $tags);
        $this->assertSame('conn_5_1', $builder->resolveNodeTag($conn, 'n1'));
        $this->assertSame('conn_5_2', $builder->resolveNodeTag($conn, 'n2'));
    }

    public function test_single_mode_resolves_only_selected_node(): void
    {
        $conn = new ResolverConnection([
            'id' => 7,
            'kind' => ResolverConnection::KIND_SUBSCRIPTION,
            'subscription_mode' => ResolverConnection::MODE_SINGLE,
            'subscription_selected' => 'sel',
            'enabled' => true,
            'outbound' => ['type' => 'vless', 'server' => 'x.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000002'],
            'subscription_nodes' => [
                ['key' => 'sel', 'outbound' => ['type' => 'vless', 'server' => 'x.example', 'server_port' => 443, 'uuid' => '00000000-0000-0000-0000-000000000002']],
                ['key' => 'other', 'outbound' => ['type' => 'ss', 'server' => 'y.example', 'server_port' => 443, 'password' => 'p', 'method' => 'aes-256-gcm']],
            ],
        ]);

        $builder = new ConnectionOutboundBuilder(new SingBoxOutboundParser);
        $this->assertSame('conn_7', $builder->resolveNodeTag($conn, 'sel'));
        $this->assertNull($builder->resolveNodeTag($conn, 'other'));
    }
}
