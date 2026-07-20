<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('awg_config_peers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('awg_config_id')->constrained('awg_configs')->cascadeOnDelete();
            $table->foreignId('vpn_client_id')->constrained('vpn_clients')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->text('private_key');
            $table->string('public_key');
            $table->string('preshared_key')->nullable();
            $table->string('address');
            $table->json('extra_allowed_ips')->nullable();
            $table->unsignedInteger('keepalive')->nullable();
            $table->timestamps();

            $table->unique(['awg_config_id', 'vpn_client_id']);
            $table->unique(['awg_config_id', 'public_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awg_config_peers');
    }
};
