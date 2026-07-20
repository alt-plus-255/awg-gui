<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('awg_configs') || ! Schema::hasTable('awg_config_peers')) {
            return;
        }

        if (DB::table('awg_configs')->exists()) {
            return;
        }

        $settings = DB::table('settings')->pluck('value', 'key');

        $subnet = $settings['internal_subnet'] ?? env('INTERNAL_SUBNET', '10.66.66.0/24');
        $serverAddress = $settings['server_address'] ?? '10.66.66.1/24';
        if (! $settings->has('server_address') && preg_match('#^(\d+\.\d+\.\d+)\.(\d+)/(\d+)$#', $subnet, $m)) {
            $serverAddress = $m[1].'.1/'.$m[3];
        }

        $configId = DB::table('awg_configs')->insertGetId([
            'name' => 'Default',
            'type' => 'server',
            'iface' => 'awg0',
            'listen_port' => (int) ($settings['awg_port'] ?? env('AWG_PORT', 51820)),
            'internal_subnet' => $subnet,
            'server_address' => $serverAddress,
            'server_private_key' => $settings['server_private_key'] ?? '',
            'server_public_key' => $settings['server_public_key'] ?? '',
            'peer_dns' => $settings['peer_dns'] ?? env('PEER_DNS', '1.1.1.1'),
            'client_allowed_ips' => $settings['allowed_ips'] ?? env('ALLOWED_IPS', '0.0.0.0/0, ::/0'),
            'persistent_keepalive' => (int) ($settings['persistent_keepalive'] ?? env('PERSISTENT_KEEPALIVE', 25)),
            'enabled' => true,
            'jc' => $settings['jc'] ?? '4',
            'jmin' => $settings['jmin'] ?? '64',
            'jmax' => $settings['jmax'] ?? '80',
            's1' => $settings['s1'] ?? '0',
            's2' => $settings['s2'] ?? '0',
            's3' => $settings['s3'] ?? '0',
            's4' => $settings['s4'] ?? '0',
            'h1' => $settings['h1'] ?? '1',
            'h2' => $settings['h2'] ?? '2',
            'h3' => $settings['h3'] ?? '3',
            'h4' => $settings['h4'] ?? '4',
            'i1' => $settings['i1'] ?? null,
            'i2' => $settings['i2'] ?? null,
            'i3' => $settings['i3'] ?? null,
            'i4' => $settings['i4'] ?? null,
            'i5' => $settings['i5'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (DB::table('vpn_clients')->orderBy('id')->get() as $client) {
            DB::table('awg_config_peers')->insert([
                'awg_config_id' => $configId,
                'vpn_client_id' => $client->id,
                'enabled' => (bool) $client->enabled,
                'private_key' => $client->private_key,
                'public_key' => $client->public_key,
                'preshared_key' => $client->preshared_key,
                'address' => $client->address,
                'extra_allowed_ips' => $client->extra_allowed_ips,
                'keepalive' => $client->keepalive,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('vpn_clients', function (Blueprint $table) {
            $table->dropColumn([
                'enabled',
                'private_key',
                'public_key',
                'preshared_key',
                'address',
                'extra_allowed_ips',
                'keepalive',
            ]);
        });

        $removeKeys = [
            'awg_port', 'internal_subnet', 'server_address', 'server_private_key',
            'server_public_key', 'allowed_ips', 'peer_dns', 'persistent_keepalive',
            'jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4', 'h1', 'h2', 'h3', 'h4',
            'i1', 'i2', 'i3', 'i4', 'i5',
        ];
        DB::table('settings')->whereIn('key', $removeKeys)->delete();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vpn_clients', 'address')) {
            Schema::table('vpn_clients', function (Blueprint $table) {
                $table->boolean('enabled')->default(true);
                $table->string('private_key')->default('');
                $table->string('public_key')->default('');
                $table->string('preshared_key')->nullable();
                $table->string('address')->default('');
                $table->json('extra_allowed_ips')->nullable();
                $table->unsignedInteger('keepalive')->nullable();
            });
        }

        $config = DB::table('awg_configs')->where('iface', 'awg0')->first();
        if ($config) {
            foreach (DB::table('awg_config_peers')->where('awg_config_id', $config->id)->get() as $peer) {
                DB::table('vpn_clients')->where('id', $peer->vpn_client_id)->update([
                    'enabled' => $peer->enabled,
                    'private_key' => $peer->private_key,
                    'public_key' => $peer->public_key,
                    'preshared_key' => $peer->preshared_key,
                    'address' => $peer->address,
                    'extra_allowed_ips' => $peer->extra_allowed_ips,
                    'keepalive' => $peer->keepalive,
                ]);
            }
        }

        Schema::dropIfExists('awg_config_peers');
        Schema::dropIfExists('awg_configs');
    }
};
