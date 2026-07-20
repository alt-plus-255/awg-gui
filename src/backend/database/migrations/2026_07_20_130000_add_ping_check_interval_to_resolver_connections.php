<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('resolver_connections', 'ping_check_interval_min')) {
                $table->unsignedSmallInteger('ping_check_interval_min')->default(5)->after('latency_cache');
            }
            if (! Schema::hasColumn('resolver_connections', 'ping_last_checked_at')) {
                $table->timestamp('ping_last_checked_at')->nullable()->after('ping_check_interval_min');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (Schema::hasColumn('resolver_connections', 'ping_last_checked_at')) {
                $table->dropColumn('ping_last_checked_at');
            }
            if (Schema::hasColumn('resolver_connections', 'ping_check_interval_min')) {
                $table->dropColumn('ping_check_interval_min');
            }
        });
    }
};
