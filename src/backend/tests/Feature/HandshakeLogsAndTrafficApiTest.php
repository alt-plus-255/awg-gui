<?php

namespace Tests\Feature;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\AwgHandshakeLog;
use App\Models\User;
use App\Models\VpnClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandshakeLogsAndTrafficApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_toggle_and_handshake_log_endpoints(): void
    {
        $user = User::factory()->create();
        [$config, $membership] = $this->createFixture();

        $config->handshake_logging_enabled = true;
        $config->save();

        $this->actingAs($user)
            ->getJson('/api/configs/'.$config->id)
            ->assertOk()
            ->assertJsonPath('config.handshake_logging_enabled', true);

        AwgHandshakeLog::query()->create([
            'awg_config_id' => $config->id,
            'awg_config_peer_id' => $membership->id,
            'vpn_client_id' => $membership->vpn_client_id,
            'public_key' => $membership->public_key,
            'endpoint' => '9.9.9.9:1',
            'handshake_at' => time(),
            'byte_size' => 120,
            'created_at' => now(),
        ]);
        $config->handshake_log_bytes = 120;
        $config->save();

        $this->actingAs($user)
            ->getJson('/api/configs/'.$config->id.'/handshake-logs')
            ->assertOk()
            ->assertJsonPath('logging_enabled', true)
            ->assertJsonPath('log_bytes', 120)
            ->assertJsonCount(1, 'logs');

        $this->actingAs($user)
            ->getJson('/api/configs/'.$config->id.'/peers/'.$membership->vpn_client_id.'/handshake-logs')
            ->assertOk()
            ->assertJsonCount(1, 'logs');

        $this->actingAs($user)
            ->deleteJson('/api/configs/'.$config->id.'/handshake-logs')
            ->assertOk()
            ->assertJsonPath('log_bytes', 0);

        $this->assertSame(0, AwgHandshakeLog::query()->count());
    }

    public function test_reset_traffic_endpoints(): void
    {
        $user = User::factory()->create();
        [$config, $membership] = $this->createFixture();

        $membership->transfer_rx = 100;
        $membership->transfer_tx = 200;
        $membership->traffic_rx_total = 5000;
        $membership->traffic_tx_total = 6000;
        $membership->save();

        $this->actingAs($user)
            ->postJson('/api/configs/'.$config->id.'/peers/'.$membership->vpn_client_id.'/reset-traffic')
            ->assertOk()
            ->assertJsonPath('membership.traffic_rx_total', 0)
            ->assertJsonPath('membership.traffic_tx_total', 0);

        $membership->refresh();
        $this->assertSame(100, (int) $membership->traffic_rx_baseline);
        $this->assertSame(200, (int) $membership->traffic_tx_baseline);

        $membership->traffic_rx_total = 10;
        $membership->traffic_tx_total = 20;
        $membership->save();

        $this->actingAs($user)
            ->postJson('/api/configs/'.$config->id.'/reset-traffic')
            ->assertOk()
            ->assertJsonPath('reset_count', 1);

        $membership->refresh();
        $this->assertSame(0, (int) $membership->traffic_rx_total);
        $this->assertSame(0, (int) $membership->traffic_tx_total);
    }

    /**
     * @return array{0: AwgConfig, 1: AwgConfigPeer}
     */
    private function createFixture(): array
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -6);
        $config = AwgConfig::query()->create([
            'name' => 'Api Traffic '.$suffix,
            'type' => 'server',
            'iface' => 'at'.$suffix,
            'listen_port' => 44000 + (hexdec(substr($suffix, 0, 4)) % 7000),
            'internal_subnet' => '10.88.'.(hexdec(substr($suffix, 0, 2)) % 200).'.0/24',
            'server_address' => '10.88.0.1/24',
            'server_private_key' => 'priv',
            'server_public_key' => 'pub'.$suffix,
            'enabled' => true,
            'handshake_logging_enabled' => false,
        ]);

        $client = VpnClient::query()->create([
            'name' => 'api-peer-'.$suffix,
        ]);

        $membership = AwgConfigPeer::query()->create([
            'awg_config_id' => $config->id,
            'vpn_client_id' => $client->id,
            'enabled' => true,
            'private_key' => 'priv-peer',
            'public_key' => 'pub-peer-'.$suffix,
            'address' => '10.88.0.2/32',
        ]);

        return [$config->fresh(), $membership->fresh()];
    }
}
