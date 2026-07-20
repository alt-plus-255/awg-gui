<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_config_peers', function (Blueprint $table) {
            $table->json('excluded_client_ids')->nullable()->after('extra_allowed_ips');
            $table->boolean('exclusions_mutual')->default(false)->after('excluded_client_ids');
        });
    }

    public function down(): void
    {
        Schema::table('awg_config_peers', function (Blueprint $table) {
            $table->dropColumn(['excluded_client_ids', 'exclusions_mutual']);
        });
    }
};
