<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('resolver_connections', 'last_tspu_meta')) {
                $table->json('last_tspu_meta')->nullable()->after('last_tspu_detail');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (Schema::hasColumn('resolver_connections', 'last_tspu_meta')) {
                $table->dropColumn('last_tspu_meta');
            }
        });
    }
};
