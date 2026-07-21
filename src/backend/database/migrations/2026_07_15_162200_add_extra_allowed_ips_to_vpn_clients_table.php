<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vpn_clients')) {
            return;
        }

        if (! Schema::hasColumn('vpn_clients', 'address')) {
            return;
        }

        if (Schema::hasColumn('vpn_clients', 'extra_allowed_ips')) {
            return;
        }

        Schema::table('vpn_clients', function (Blueprint $table) {
            $table->json('extra_allowed_ips')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('vpn_clients') || ! Schema::hasColumn('vpn_clients', 'extra_allowed_ips')) {
            return;
        }

        Schema::table('vpn_clients', function (Blueprint $table) {
            $table->dropColumn('extra_allowed_ips');
        });
    }
};
