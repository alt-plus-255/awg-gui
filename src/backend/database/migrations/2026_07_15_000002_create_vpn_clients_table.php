<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('comment')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('private_key');
            $table->string('public_key');
            $table->string('preshared_key')->nullable();
            $table->string('address'); // e.g. 10.66.66.2/32
            $table->unsignedInteger('keepalive')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_clients');
    }
};
