<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (! Schema::hasColumn('awg_configs', 'resolver_reject_quic')) {
                $table->boolean('resolver_reject_quic')->default(false)->after('resolver_enabled');
            }
        });

        // Preserve old YouTube-specific QUIC reject behavior for configs that had youtube selected.
        if (Schema::hasColumn('awg_configs', 'resolver_reject_quic')) {
            $rows = DB::table('awg_configs')->select('id', 'community_lists')->get();
            foreach ($rows as $row) {
                $lists = json_decode((string) ($row->community_lists ?? '[]'), true);
                if (! is_array($lists)) {
                    continue;
                }
                if (in_array('youtube', $lists, true)) {
                    DB::table('awg_configs')->where('id', $row->id)->update(['resolver_reject_quic' => true]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('awg_configs', function (Blueprint $table) {
            if (Schema::hasColumn('awg_configs', 'resolver_reject_quic')) {
                $table->dropColumn('resolver_reject_quic');
            }
        });
    }
};
