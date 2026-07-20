<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LoginProtectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminResetPasswordCommand extends Command
{
    protected $signature = 'admin:reset-password
                            {--username=admin : Admin username}
                            {--password= : Set this password}
                            {--random : Generate a random password (default when --password is omitted)}';

    protected $description = 'Reset the admin user password';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $user = User::query()->where('username', $username)->first();

        if (! $user) {
            $this->error("User '{$username}' not found");

            return self::FAILURE;
        }

        $password = $this->option('password');
        $random = (bool) $this->option('random');

        if ($random && $password) {
            $this->error('Use either --password or --random, not both.');

            return self::FAILURE;
        }

        if (! $password) {
            if ($this->input->isInteractive() && ! $random) {
                $password = $this->secret('New password (empty = generate)');
            }
            if (! $password) {
                $password = Str::password(20);
            }
        }

        $user->password = Hash::make($password);
        $user->save();

        app(LoginProtectionService::class)->clearAll();

        $this->info("Password updated for '{$username}'.");
        $this->line("New password: {$password}");
        $this->info('Login attempt counters and IP lockouts cleared.');

        return self::SUCCESS;
    }
}
