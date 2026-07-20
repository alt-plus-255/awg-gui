<?php

namespace App\Console\Commands;

use App\Services\AmneziaWg\AmneziaWgService;
use Illuminate\Console\Command;

class AwgBootstrapCommand extends Command
{
    protected $signature = 'awg:bootstrap';

    protected $description = 'Initialize AmneziaWG server keys, junk params and config file';

    public function handle(AmneziaWgService $awg): int
    {
        $awg->ensureDbDefaults();
        $awg->bootstrapRuntime();
        $this->info('AmneziaWG defaults ensured and config written.');

        return self::SUCCESS;
    }
}
