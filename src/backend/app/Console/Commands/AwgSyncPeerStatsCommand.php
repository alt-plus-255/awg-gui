<?php

namespace App\Console\Commands;

use App\Services\AmneziaWg\PeerStatsSyncService;
use Illuminate\Console\Command;

class AwgSyncPeerStatsCommand extends Command
{
    protected $signature = 'awg:sync-peer-stats';

    protected $description = 'Sync peer runtime stats, accumulate traffic totals, and record handshake logs';

    public function handle(PeerStatsSyncService $statsSync): int
    {
        $statsSync->refreshFromDocker();

        return self::SUCCESS;
    }
}
