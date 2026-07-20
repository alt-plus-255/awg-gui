<?php

namespace App\Console\Commands;

use App\Services\Resolver\ResolverService;
use Illuminate\Console\Command;

class ResolverRefreshCommand extends Command
{
    protected $signature = 'resolver:refresh {--force-lists : Re-download community .srs from GitHub}';

    protected $description = 'Regenerate sing-box resolver config and reload';

    public function handle(ResolverService $resolver): int
    {
        try {
            $resolver->apply(
                refreshSubscriptions: true,
                urltestRoutingRetry: true,
                forceSyncLists: (bool) $this->option('force-lists'),
            );
            $this->info('Resolver config refreshed');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
