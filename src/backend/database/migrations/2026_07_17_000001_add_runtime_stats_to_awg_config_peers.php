<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_config_peers', function (Blueprint $table) {
            $table->string('runtime_endpoint', 64)->nullable()->after('keepalive');
            $table->unsignedBigInteger('latest_handshake')->nullable()->after('runtime_endpoint');
            $table->unsignedBigInteger('transfer_rx')->default(0)->after('latest_handshake');
            $table->unsignedBigInteger('transfer_tx')->default(0)->after('transfer_rx');
            $table->boolean('online')->nullable()->after('transfer_tx');
            $table->timestamp('stats_synced_at')->nullable()->after('online');
        });
    }

    public function down(): void
    {
        Schema::table('awg_config_peers', function (Blueprint $table) {
            $table->dropColumn([
                'runtime_endpoint',
                'latest_handshake',
                'transfer_rx',
                'transfer_tx',
                'online',
                'stats_synced_at',
            ]);
        });
    }
};
