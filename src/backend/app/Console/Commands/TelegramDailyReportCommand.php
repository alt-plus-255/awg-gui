<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramDailyReportNotifier;
use Illuminate\Console\Command;

class TelegramDailyReportCommand extends Command
{
    protected $signature = 'telegram:daily-report';

    protected $description = 'Send Telegram daily status report to the admin';

    public function handle(TelegramDailyReportNotifier $notifier): int
    {
        if ($notifier->send()) {
            $this->info('Daily report sent.');
        } else {
            $this->info('Daily report skipped (disabled or not configured).');
        }

        return self::SUCCESS;
    }
}
