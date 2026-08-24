<?php

namespace Tests\Unit;

use App\Models\AwgConfig;
use App\Models\AwgConfigPeer;
use App\Models\AwgHandshakeLog;
use App\Models\VpnClient;
use App\Services\AmneziaWg\AmneziaWgService;
use App\Services\AmneziaWg\HandshakeLogService;
use App\Services\AmneziaWg\PeerStatsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PeerTrafficAndHandshakeLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_accumulate_delta_grows_and_handles_counter_reset(): void
    {
        $grow = PeerStatsSyncService::accumulateDelta(1500, 1000);
        $this->assertSame(500, $grow['delta']);
        $this->assertSame(1500, $grow['baseline']);

        $reset = PeerStatsSyncService::accumulateDelta(40, 1500);
        $this->assertSame(40, $reset['delta']);
        $this->assertSame(40, $reset['baseline']);

        $same = PeerStatsSyncService::accumulateDelta(40, 40);
        $this->assertSame(0, $same['delta']);
        $this->assertSame(40, $same['baseline']);
    }

    public function test_refresh_accumulates_traffic_and_records_handshake_when_enabled(): void
    {
        [$config, $membership] = $this->seedConfigPeer([
            'handshake_logging_enabled' => true,
        ]);

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('livePeerStats')->once()->andReturn([
            'stats_available' => true,
            'by_public_key' => [
                $membership->public_key => [
                    'endpoint' => '1.2.3.4:51820',
                    'latest_handshake' => 1_700_000_100,
                    'latest_handshake_human' => date('c', 1_700_000_100),
                    'transfer_rx' => 1000,
                    'transfer_tx' => 2000,
                    'online' => true,
                ],
            ],
        ]);

        $sync = new PeerStatsSyncService($awg, app(HandshakeLogService::class));
        $result = $sync->refreshFromDocker($config->id);

        $membership->refresh();
        $this->assertSame(1000, (int) $membership->transfer_rx);
        $this->assertSame(2000, (int) $membership->transfer_tx);
        $this->assertSame(1000, (int) $membership->traffic_rx_total);
        $this->assertSame(2000, (int) $membership->traffic_tx_total);
        $this->assertSame(1000, (int) $membership->traffic_rx_baseline);
        $this->assertSame(1_700_000_100, (int) $membership->latest_handshake);
        $this->assertSame(1, AwgHandshakeLog::query()->count());
        $this->assertSame(1000, $result['by_public_key'][$membership->public_key]['traffic_rx_total']);

        $awg2 = Mockery::mock(AmneziaWgService::class);
        $awg2->shouldReceive('livePeerStats')->once()->andReturn([
            'stats_available' => true,
            'by_public_key' => [
                $membership->public_key => [
                    'endpoint' => '1.2.3.4:51820',
                    'latest_handshake' => 1_700_000_200,
                    'latest_handshake_human' => date('c', 1_700_000_200),
                    'transfer_rx' => 1500,
                    'transfer_tx' => 2500,
                    'online' => true,
                ],
            ],
        ]);

        $sync2 = new PeerStatsSyncService($awg2, app(HandshakeLogService::class));
        $sync2->refreshFromDocker($config->id);

        $membership->refresh();
        $this->assertSame(1500, (int) $membership->traffic_rx_total);
        $this->assertSame(2500, (int) $membership->traffic_tx_total);
        $this->assertSame(2, AwgHandshakeLog::query()->count());
    }

    public function test_handshake_not_logged_when_disabled_or_unchanged(): void
    {
        [$config, $membership] = $this->seedConfigPeer([
            'handshake_logging_enabled' => false,
        ]);
        $membership->latest_handshake = 1_700_000_100;
        $membership->save();

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('livePeerStats')->once()->andReturn([
            'stats_available' => true,
            'by_public_key' => [
                $membership->public_key => [
                    'endpoint' => null,
                    'latest_handshake' => 1_700_000_200,
                    'transfer_rx' => 10,
                    'transfer_tx' => 20,
                    'online' => true,
                ],
            ],
        ]);

        $sync = new PeerStatsSyncService($awg, app(HandshakeLogService::class));
        $sync->refreshFromDocker($config->id);
        $this->assertSame(0, AwgHandshakeLog::query()->count());

        $config->handshake_logging_enabled = true;
        $config->save();
        $membership->refresh();

        $awgSame = Mockery::mock(AmneziaWgService::class);
        $awgSame->shouldReceive('livePeerStats')->once()->andReturn([
            'stats_available' => true,
            'by_public_key' => [
                $membership->public_key => [
                    'endpoint' => null,
                    'latest_handshake' => (int) $membership->latest_handshake,
                    'transfer_rx' => 10,
                    'transfer_tx' => 20,
                    'online' => true,
                ],
            ],
        ]);
        (new PeerStatsSyncService($awgSame, app(HandshakeLogService::class)))->refreshFromDocker($config->id);
        $this->assertSame(0, AwgHandshakeLog::query()->count());
    }

    public function test_missing_peer_in_dump_does_not_clear_totals(): void
    {
        [$config, $membership] = $this->seedConfigPeer();
        $membership->traffic_rx_total = 5000;
        $membership->traffic_tx_total = 7000;
        $membership->traffic_rx_baseline = 5000;
        $membership->traffic_tx_baseline = 7000;
        $membership->transfer_rx = 5000;
        $membership->transfer_tx = 7000;
        $membership->save();

        $awg = Mockery::mock(AmneziaWgService::class);
        $awg->shouldReceive('livePeerStats')->once()->andReturn([
            'stats_available' => true,
            'by_public_key' => [],
        ]);

        (new PeerStatsSyncService($awg, app(HandshakeLogService::class)))->refreshFromDocker($config->id);

        $membership->refresh();
        $this->assertSame(0, (int) $membership->transfer_rx);
        $this->assertSame(5000, (int) $membership->traffic_rx_total);
        $this->assertSame(7000, (int) $membership->traffic_tx_total);
    }

    public function test_handshake_log_trim_removes_oldest_when_over_limit(): void
    {
        [$config, $membership] = $this->seedConfigPeer([
            'handshake_logging_enabled' => true,
        ]);

        $svc = app(HandshakeLogService::class);

        // Force small limit by writing oversized byte_size values and calling trim.
        for ($i = 0; $i < 5; $i++) {
            AwgHandshakeLog::query()->create([
                'awg_config_id' => $config->id,
                'awg_config_peer_id' => $membership->id,
                'vpn_client_id' => $membership->vpn_client_id,
                'public_key' => $membership->public_key,
                'endpoint' => '1.1.1.'.$i,
                'handshake_at' => 1_700_000_000 + $i,
                'byte_size' => 3 * 1024 * 1024,
                'created_at' => now()->subMinutes(5 - $i),
            ]);
        }
        $config->handshake_log_bytes = 15 * 1024 * 1024;
        $config->save();

        $svc->trimToLimit($config);

        $config->refresh();
        $this->assertLessThanOrEqual(HandshakeLogService::BYTE_LIMIT, (int) $config->handshake_log_bytes);
        $this->assertLessThanOrEqual(HandshakeLogService::BYTE_TRIM_TARGET, (int) $config->handshake_log_bytes);
        $this->assertSame(
            (int) AwgHandshakeLog::query()->where('awg_config_id', $config->id)->sum('byte_size'),
            (int) $config->handshake_log_bytes
        );
        $this->assertGreaterThan(0, AwgHandshakeLog::query()->count());
        $this->assertLessThan(5, AwgHandshakeLog::query()->count());
        $this->assertSame(
            AwgHandshakeLog::query()->orderBy('id')->value('handshake_at'),
            AwgHandshakeLog::query()->orderBy('id')->min('handshake_at')
        );
    }

    public function test_reset_peer_traffic_zeros_totals_and_keeps_baseline(): void
    {
        [$config, $membership] = $this->seedConfigPeer();
        $membership->transfer_rx = 123;
        $membership->transfer_tx = 456;
        $membership->traffic_rx_total = 999;
        $membership->traffic_tx_total = 888;
        $membership->traffic_rx_baseline = 50;
        $membership->traffic_tx_baseline = 60;
        $membership->save();

        $awg = Mockery::mock(AmneziaWgService::class);
        $sync = new PeerStatsSyncService($awg, app(HandshakeLogService::class));
        $sync->resetPeerTraffic($membership);
        $membership->refresh();

        $this->assertSame(0, (int) $membership->traffic_rx_total);
        $this->assertSame(0, (int) $membership->traffic_tx_total);
        $this->assertSame(123, (int) $membership->traffic_rx_baseline);
        $this->assertSame(456, (int) $membership->traffic_tx_baseline);
        $this->assertNotNull($membership->traffic_reset_at);
    }

    /**
     * @param  array<string, mixed>  $configAttrs
     * @return array{0: AwgConfig, 1: AwgConfigPeer}
     */
    private function seedConfigPeer(array $configAttrs = []): array
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -6);
        $config = AwgConfig::query()->create(array_merge([
            'name' => 'Traffic Test '.$suffix,
            'type' => 'server',
            'iface' => 'tt'.$suffix,
            'listen_port' => 43000 + (hexdec(substr($suffix, 0, 4)) % 8000),
            'internal_subnet' => '10.77.'.(hexdec(substr($suffix, 0, 2)) % 200).'.0/24',
            'server_address' => '10.77.0.1/24',
            'server_private_key' => 'priv',
            'server_public_key' => 'pub'.$suffix,
            'enabled' => true,
            'handshake_logging_enabled' => false,
        ], $configAttrs));

        $client = VpnClient::query()->create([
            'name' => 'peer-'.$suffix,
            'comment' => null,
        ]);

        $membership = AwgConfigPeer::query()->create([
            'awg_config_id' => $config->id,
            'vpn_client_id' => $client->id,
            'enabled' => true,
            'private_key' => 'priv-peer',
            'public_key' => 'pub-peer-'.$suffix,
            'address' => '10.77.0.2/32',
        ]);

        return [$config->fresh(), $membership->fresh(['config', 'client'])];
    }
}
