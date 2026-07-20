<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('resolver_connections', 'last_latency_ms')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->unsignedInteger('last_latency_ms')->nullable()->after('enabled');
            });
        }
        if (! Schema::hasColumn('resolver_connections', 'last_tested_at')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->timestamp('last_tested_at')->nullable()->after('last_latency_ms');
            });
        }
        if (! Schema::hasColumn('resolver_connections', 'last_test_ok')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->boolean('last_test_ok')->nullable()->after('last_tested_at');
            });
        }
        if (! Schema::hasColumn('resolver_connections', 'last_test_error')) {
            Schema::table('resolver_connections', function (Blueprint $table) {
                $table->text('last_test_error')->nullable()->after('last_test_ok');
            });
        }
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            $cols = [];
            foreach (['last_latency_ms', 'last_tested_at', 'last_test_ok', 'last_test_error'] as $col) {
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
