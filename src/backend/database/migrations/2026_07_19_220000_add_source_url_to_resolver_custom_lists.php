<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resolver_custom_lists')) {
            return;
        }
        Schema::table('resolver_custom_lists', function (Blueprint $table) {
            if (! Schema::hasColumn('resolver_custom_lists', 'source_url')) {
                $table->string('source_url', 1024)->nullable()->after('cidrs');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('resolver_custom_lists')) {
            return;
        }
        Schema::table('resolver_custom_lists', function (Blueprint $table) {
            if (Schema::hasColumn('resolver_custom_lists', 'source_url')) {
                $table->dropColumn('source_url');
            }
        });
    }
};
