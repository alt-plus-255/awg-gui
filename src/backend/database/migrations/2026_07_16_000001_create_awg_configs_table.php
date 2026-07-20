<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('awg_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 32)->default('server'); // server | virtual_network
            $table->string('iface', 16)->unique();
            $table->unsignedInteger('listen_port')->unique();
            $table->string('internal_subnet');
            $table->string('server_address');
            $table->text('server_private_key');
            $table->string('server_public_key');
            $table->string('peer_dns')->default('1.1.1.1');
            $table->string('client_allowed_ips')->default('0.0.0.0/0, ::/0');
            $table->unsignedInteger('persistent_keepalive')->default(25);
            $table->boolean('enabled')->default(true);
            $table->string('jc', 10)->default('4');
            $table->string('jmin', 10)->default('64');
            $table->string('jmax', 10)->default('80');
            $table->string('s1', 10)->default('0');
            $table->string('s2', 10)->default('0');
            $table->string('s3', 10)->default('0');
            $table->string('s4', 10)->default('0');
            $table->string('h1', 20)->default('1');
            $table->string('h2', 20)->default('2');
            $table->string('h3', 20)->default('3');
            $table->string('h4', 20)->default('4');
            $table->text('i1')->nullable();
            $table->text('i2')->nullable();
            $table->text('i3')->nullable();
            $table->text('i4')->nullable();
            $table->text('i5')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('awg_configs');
    }
};
