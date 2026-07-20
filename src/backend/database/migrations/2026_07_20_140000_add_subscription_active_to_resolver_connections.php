<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resolver_connections', 'subscription_active')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->json('subscription_active')->nullable()->after('latency_cache');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('resolver_connections', 'subscription_active')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->dropColumn('subscription_active');
            });
        }
    }
};
