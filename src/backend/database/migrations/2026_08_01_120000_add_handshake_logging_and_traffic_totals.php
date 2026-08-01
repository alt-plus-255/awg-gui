<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            $table->boolean('handshake_logging_enabled')->default(false)->after('enabled');
            $table->unsignedBigInteger('handshake_log_bytes')->default(0)->after('handshake_logging_enabled');
        });

        Schema::table('awg_config_peers', function (Blueprint $table) {
            $table->unsignedBigInteger('traffic_rx_total')->default(0)->after('transfer_tx');
            $table->unsignedBigInteger('traffic_tx_total')->default(0)->after('traffic_rx_total');
            $table->unsignedBigInteger('traffic_rx_baseline')->default(0)->after('traffic_tx_total');
            $table->unsignedBigInteger('traffic_tx_baseline')->default(0)->after('traffic_rx_baseline');
            $table->timestamp('traffic_reset_at')->nullable()->after('traffic_tx_baseline');
        });

        Schema::create('awg_handshake_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('awg_config_id')->constrained('awg_configs')->cascadeOnDelete();
            $table->foreignId('awg_config_peer_id')->nullable()->constrained('awg_config_peers')->nullOnDelete();
            $table->unsignedBigInteger('vpn_client_id')->nullable();
            $table->string('public_key', 64);
            $table->string('endpoint', 64)->nullable();
            $table->unsignedBigInteger('handshake_at');
            $table->unsignedInteger('byte_size')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['awg_config_id', 'id']);
            $table->index(['awg_config_id', 'vpn_client_id', 'id']);
            $table->index(['awg_config_id', 'handshake_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awg_handshake_logs');

        Schema::table('awg_config_peers', function (Blueprint $table) {
            $table->dropColumn([
                'traffic_rx_total',
                'traffic_tx_total',
                'traffic_rx_baseline',
                'traffic_tx_baseline',
                'traffic_reset_at',
            ]);
        });

        Schema::table('awg_configs', function (Blueprint $table) {
            $table->dropColumn([
                'handshake_logging_enabled',
                'handshake_log_bytes',
            ]);
        });
    }
};
