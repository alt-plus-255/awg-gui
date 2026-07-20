<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('resolver_connections', 'last_tspu_status')) {
                $table->string('last_tspu_status', 32)->nullable()->after('last_test_error');
            }
            if (! Schema::hasColumn('resolver_connections', 'last_tspu_likely')) {
                $table->boolean('last_tspu_likely')->nullable()->after('last_tspu_status');
            }
            if (! Schema::hasColumn('resolver_connections', 'last_tspu_detail')) {
                $table->text('last_tspu_detail')->nullable()->after('last_tspu_likely');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            $cols = [];
            foreach (['last_tspu_status', 'last_tspu_likely', 'last_tspu_detail'] as $col) {
                if (Schema::hasColumn('resolver_connections', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
