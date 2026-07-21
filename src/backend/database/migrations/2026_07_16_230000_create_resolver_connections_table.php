<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resolver_connections')) {
            Schema::create('resolver_connections', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('comment')->nullable();
                $table->string('config_type', 16)->default('url'); // url | json
                $table->text('share_url')->nullable();
                $table->json('outbound');
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        Schema::table('awg_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('awg_configs', 'connection_id')) {
                $table->foreignId('connection_id')
                    ->nullable()
                    ->after('resolver_last_error')
                    ->constrained('resolver_connections')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (Schema::hasColumn('awg_configs', 'connection_id')) {
                $table->dropConstrainedForeignId('connection_id');
            }
        });
        Schema::dropIfExists('resolver_connections');
    }
};
