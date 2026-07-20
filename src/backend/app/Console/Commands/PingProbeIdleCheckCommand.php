<?php

namespace App\Console\Commands;

use App\Services\Resolver\PingProbeManager;
use Illuminate\Console\Command;

class PingProbeIdleCheckCommand extends Command
{
    protected $signature = 'resolver:probe-idle-check';

    protected $description = 'Stop sing-box ping probe after idle timeout';

    public function handle(PingProbeManager $probe): int
    {
        $probe->stopIfIdle();

        return self::SUCCESS;
    }
}
