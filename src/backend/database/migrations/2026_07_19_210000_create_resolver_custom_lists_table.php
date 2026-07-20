<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resolver_custom_lists')) {
            Schema::create('resolver_custom_lists', function (Blueprint $table) {
                $table->id();
                $table->string('name', 128);
                $table->string('slug', 64)->unique();
                $table->json('domains')->nullable();
                $table->json('cidrs')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('resolver_custom_lists');
    }
};
