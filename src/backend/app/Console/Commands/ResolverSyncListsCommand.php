<?php

namespace App\Console\Commands;

use App\Services\Resolver\ResolverListsService;
use Illuminate\Console\Command;

class ResolverSyncListsCommand extends Command
{
    protected $signature = 'resolver:sync-lists {--force : Download all community lists now, ignoring interval}';

    protected $description = 'Sync community resolver lists when interval elapsed (or --force)';

    public function handle(ResolverListsService $lists): int
    {
        try {
            if ($this->option('force')) {
                $lists->syncCommunity(null, true);
                $this->info('All community lists force-synced');

                return self::SUCCESS;
            }

            if (! $lists->syncIfDue()) {
                $this->line('Skip: interval not due and no missing selected lists');

                return self::SUCCESS;
            }

            $this->info('Community lists synced');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
