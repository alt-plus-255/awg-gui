<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('resolver_connections', 'kind')) {
                $table->string('kind', 16)->default('proxy')->after('comment');
            }
            if (! Schema::hasColumn('resolver_connections', 'subscription_url')) {
                $table->text('subscription_url')->nullable()->after('share_url');
            }
            if (! Schema::hasColumn('resolver_connections', 'subscription_mode')) {
                $table->string('subscription_mode', 16)->nullable()->after('subscription_url');
            }
            if (! Schema::hasColumn('resolver_connections', 'subscription_selected')) {
                $table->string('subscription_selected', 64)->nullable()->after('subscription_mode');
            }
            if (! Schema::hasColumn('resolver_connections', 'subscription_nodes')) {
                $table->json('subscription_nodes')->nullable()->after('subscription_selected');
            }
            if (! Schema::hasColumn('resolver_connections', 'subscription_fetched_at')) {
                $table->timestamp('subscription_fetched_at')->nullable()->after('subscription_nodes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            $cols = [];
            foreach ([
                'kind',
                'subscription_url',
                'subscription_mode',
                'subscription_selected',
                'subscription_nodes',
                'subscription_fetched_at',
            ] as $col) {
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
