<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (! Schema::hasColumn('resolver_connections', 'subscription_body')) {
                $table->longText('subscription_body')->nullable()->after('subscription_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resolver_connections', function (Blueprint $table) {
            if (Schema::hasColumn('resolver_connections', 'subscription_body')) {
                $table->dropColumn('subscription_body');
            }
        });
    }
};
