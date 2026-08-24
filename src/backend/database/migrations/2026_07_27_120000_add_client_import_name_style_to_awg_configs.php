<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('awg_configs', 'client_import_name_style')) {
                $table->string('client_import_name_style', 32)->default('peer_name')->after('protocol_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (Schema::hasColumn('awg_configs', 'client_import_name_style')) {
                $table->dropColumn('client_import_name_style');
            }
        });
    }
};
