<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('awg_configs', 'resolver_routing_mode')) {
            return;
        }

        DB::table('awg_configs')
            ->where('resolver_routing_mode', 'client_split')
            ->update(['resolver_routing_mode' => 'vds_split']);
    }

    public function down(): void
    {
        // Cannot restore which configs used client_split.
    }
};
