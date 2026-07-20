<?php

namespace App\Console\Commands;

use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Console\Command;
use RuntimeException;

class AwgSetEndpointCommand extends Command
{
    protected $signature = 'awg:set-endpoint
                            {--show : Display current public endpoint and AWG port}
                            {--endpoint= : Public IP or hostname (use "auto" for auto-detect)}
                            {--port= : AmneziaWG UDP listen port (51820-51839)}
                            {--no-restart : Do not restart AWG after port change}';

    protected $description = 'Show or update the public VPN endpoint (IP/DNS and UDP port)';

    public function handle(AmneziaWgService $awg): int
    {
        if ($this->option('show') || ($this->option('endpoint') === null && $this->option('port') === null)) {
            $status = $awg->endpointStatus();
            $this->line('server_endpoint='.$status['server_endpoint']);
            $this->line('display_endpoint='.$status['display_endpoint']);
            $this->line('awg_port='.$status['awg_port']);
            $this->line('listen_port='.($status['listen_port'] ?? ''));
            $this->line('endpoint='.$status['endpoint']);

            return self::SUCCESS;
        }

        $endpoint = $this->option('endpoint');
        $portOpt = $this->option('port');
        $port = $portOpt !== null && $portOpt !== '' ? (int) $portOpt : null;

        try {
            $status = $awg->updateServerEndpoint(
                is_string($endpoint) ? $endpoint : null,
                $port,
                ! (bool) $this->option('no-restart')
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Endpoint updated.');
        $this->line('server_endpoint='.$status['server_endpoint']);
        $this->line('display_endpoint='.$status['display_endpoint']);
        $this->line('endpoint='.$status['endpoint']);
        if ($status['restarted']) {
            $this->line('restarted=true');
        }

        return self::SUCCESS;
    }
}
