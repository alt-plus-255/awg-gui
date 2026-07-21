<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $legacyColumns = [
        'enabled',
        'private_key',
        'public_key',
        'preshared_key',
        'address',
        'extra_allowed_ips',
        'keepalive',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('vpn_clients')) {
            return;
        }

        $toDrop = array_values(array_filter(
            $this->legacyColumns,
            fn (string $column) => Schema::hasColumn('vpn_clients', $column)
        ));

        if ($toDrop === []) {
            return;
        }

        Schema::table('vpn_clients', function (Blueprint $table) use ($toDrop) {
            $table->dropColumn($toDrop);
        });
    }

    public function down(): void
    {
        // Irreversible: legacy columns belong on awg_config_peers in multi-config schema.
    }
};
