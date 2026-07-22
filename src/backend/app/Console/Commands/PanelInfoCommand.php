<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\AuthController;
use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Console\Command;

class PanelInfoCommand extends Command
{
    protected $signature = 'panel:info
                            {--json : Output machine-readable JSON}';

    protected $description = 'Show panel access info (host, port, login) without password';

    public function handle(AmneziaWgService $awg): int
    {
        $info = AuthController::panelAccessInfo($awg);

        if ($this->option('json')) {
            $this->line(json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $port = $info['ssl_enabled'] ? $info['https_port'] : $info['port'];

        $this->line('Panel access info:');
        $this->line('  URL:      '.$info['panel_url']);
        $this->line('  Host:     '.$info['host']);
        $this->line('  Port:     '.$port);
        $this->line('  Login:    '.$info['username']);

        return self::SUCCESS;
    }
}
