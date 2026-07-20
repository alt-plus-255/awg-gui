<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_protections', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('lockout_count')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_protections');
    }
};
