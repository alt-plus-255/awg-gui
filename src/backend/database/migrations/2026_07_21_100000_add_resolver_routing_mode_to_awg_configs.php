<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('awg_configs', 'resolver_routing_mode')) {
                $table->string('resolver_routing_mode', 32)
                    ->default('vds_split')
                    ->after('resolver_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (Schema::hasColumn('awg_configs', 'resolver_routing_mode')) {
                $table->dropColumn('resolver_routing_mode');
            }
        });
    }
};
