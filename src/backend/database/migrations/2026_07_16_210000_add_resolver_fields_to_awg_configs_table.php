<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('awg_configs', 'vn_policy')) {
                $table->string('vn_policy', 32)->default('allow_all')->after('type');
            }
            if (! Schema::hasColumn('awg_configs', 'vn_zones')) {
                $table->json('vn_zones')->nullable()->after('vn_policy');
            }
            if (! Schema::hasColumn('awg_configs', 'resolver_enabled')) {
                $table->boolean('resolver_enabled')->default(false)->after('enabled');
            }
            if (! Schema::hasColumn('awg_configs', 'community_lists')) {
                $table->json('community_lists')->nullable()->after('resolver_enabled');
            }
            if (! Schema::hasColumn('awg_configs', 'user_domains')) {
                $table->json('user_domains')->nullable()->after('community_lists');
            }
            if (! Schema::hasColumn('awg_configs', 'user_subnets')) {
                $table->json('user_subnets')->nullable()->after('user_domains');
            }
            if (! Schema::hasColumn('awg_configs', 'resolver_updated_at')) {
                $table->timestamp('resolver_updated_at')->nullable()->after('user_subnets');
            }
            if (! Schema::hasColumn('awg_configs', 'resolver_last_error')) {
                $table->text('resolver_last_error')->nullable()->after('resolver_updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            $cols = [
                'resolver_enabled',
                'community_lists',
                'user_domains',
                'user_subnets',
                'resolver_updated_at',
                'resolver_last_error',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('awg_configs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
