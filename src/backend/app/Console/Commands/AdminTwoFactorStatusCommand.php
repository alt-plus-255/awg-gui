<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

class AdminTwoFactorStatusCommand extends Command
{
    protected $signature = 'admin:2fa-status
                            {--username=admin : Admin username}';

    protected $description = 'Show two-factor authentication status for the admin user';

    public function handle(TwoFactorService $twoFactor): int
    {
        $username = (string) $this->option('username');
        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $this->error("User '{$username}' not found");

            return self::FAILURE;
        }

        if ($twoFactor->isEnabled($user)) {
            $this->info("2FA: enabled ({$username})");
        } elseif (filled($user->two_factor_secret)) {
            $this->warn("2FA: pending setup ({$username})");
        } else {
            $this->info("2FA: disabled ({$username})");
        }

        return self::SUCCESS;
    }
}
