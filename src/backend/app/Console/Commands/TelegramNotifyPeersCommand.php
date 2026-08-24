<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramPeerNotifier;
use Illuminate\Console\Command;

class TelegramNotifyPeersCommand extends Command
{
    protected $signature = 'telegram:notify-peers';

    protected $description = 'Notify Telegram admin about peer online/offline transitions';

    public function handle(TelegramPeerNotifier $notifier): int
    {
        $notifier->checkAndNotify();

        return self::SUCCESS;
    }
}
