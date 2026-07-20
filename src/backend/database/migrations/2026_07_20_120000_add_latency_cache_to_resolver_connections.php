<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resolver_connections', 'latency_cache')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->json('latency_cache')->nullable()->after('subscription_fetched_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('resolver_connections', 'latency_cache')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->dropColumn('latency_cache');
            });
        }
    }
};
