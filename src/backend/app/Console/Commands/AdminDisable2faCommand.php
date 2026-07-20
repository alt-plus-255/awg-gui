<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Console\Command;

class AdminDisable2faCommand extends Command
{
    protected $signature = 'admin:disable-2fa
                            {--username=admin : Admin username}';

    protected $description = 'Disable two-factor authentication for the admin user';

    public function handle(TwoFactorService $twoFactor): int
    {
        $username = (string) $this->option('username');
        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $this->error("User '{$username}' not found");

            return self::FAILURE;
        }

        if (! $twoFactor->isEnabled($user) && ! filled($user->two_factor_secret)) {
            $this->info("2FA is already disabled for '{$username}'.");

            return self::SUCCESS;
        }

        $twoFactor->disable($user);
        $this->info("2FA disabled for '{$username}'.");

        return self::SUCCESS;
    }
}
