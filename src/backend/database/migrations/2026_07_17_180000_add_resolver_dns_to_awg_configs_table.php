<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('awg_configs', 'resolver_dns')) {
                $table->string('resolver_dns', 255)->nullable()->default('1.1.1.1')->after('peer_dns');
            }
        });
    }

    public function down(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (Schema::hasColumn('awg_configs', 'resolver_dns')) {
                $table->dropColumn('resolver_dns');
            }
        });
    }
};
