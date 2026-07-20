<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vpn_clients', function (Blueprint $table) {
            $table->json('extra_allowed_ips')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('vpn_clients', function (Blueprint $table) {
            $table->dropColumn('extra_allowed_ips');
        });
    }
};
