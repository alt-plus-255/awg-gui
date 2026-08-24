<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            $table->text('awg_conf')->nullable()->after('outbound');
            $table->string('protocol_version', 16)->nullable()->after('awg_conf');
        });
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            $table->dropColumn(['awg_conf', 'protocol_version']);
        });
    }
};
