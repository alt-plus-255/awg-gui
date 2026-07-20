<?php

namespace App\Console\Commands;

use App\Services\AmneziaWg\StatsBroadcaster;
use App\WebSocket\ReactStatsWsServer;
use App\WebSocket\StatsWebSocketHandler;
use Illuminate\Console\Command;

class AwgWsServeCommand extends Command
{
    protected $signature = 'awg:ws-serve {--host=0.0.0.0} {--port=8081}';

    protected $description = 'WebSocket server for AWG live peer statistics';

    public function handle(StatsBroadcaster $broadcaster): int
    {
        $host = (string) $this->option('host');
        $port = (int) $this->option('port');

        $handler = new StatsWebSocketHandler($broadcaster);
        $server = new ReactStatsWsServer($broadcaster, $handler);

        $this->info("AWG WebSocket server listening on {$host}:{$port}");

        try {
            $server->run($host, $port);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            throw $e;
        }

        return self::SUCCESS;
    }
}
