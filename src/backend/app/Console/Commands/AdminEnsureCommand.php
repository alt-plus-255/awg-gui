<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LoginProtectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminEnsureCommand extends Command
{
    protected $signature = 'admin:ensure
                            {--username=admin : Admin username}
                            {--password= : Admin password (required when creating or with --force-password)}
                            {--email=admin@localhost : Admin email}
                            {--force-password : Overwrite password for an existing admin user}';

    protected $description = 'Ensure the admin user exists without resetting an existing password';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $email = (string) $this->option('email');
        $password = $this->option('password') ?: env('ADMIN_PASSWORD');
        $forcePassword = (bool) $this->option('force-password');

        $user = User::query()->where('username', $username)->orWhere('email', $email)->first();

        if (! $user) {
            if (! $password) {
                $this->error('Password required via --password or ADMIN_PASSWORD env');

                return self::FAILURE;
            }

            $user = new User;
            $user->username = $username;
            $user->email = $email;
            $user->name = $username;
            $user->password = Hash::make($password);
            $user->save();

            app(LoginProtectionService::class)->clearAll();
            $this->info("Admin user '{$username}' created.");

            return self::SUCCESS;
        }

        $user->username = $username;
        $user->email = $email;
        $user->name = $user->name ?: $username;

        if (! $forcePassword) {
            $user->save();
            $this->info("Admin user '{$username}' already exists (password preserved).");

            return self::SUCCESS;
        }

        if (! $password) {
            $this->error('Password required via --password or ADMIN_PASSWORD env');

            return self::FAILURE;
        }

        $passwordChanged = ! Hash::check($password, $user->password);
        $user->password = Hash::make($password);
        $user->save();

        if ($passwordChanged) {
            app(LoginProtectionService::class)->clearAll();
        }

        $this->info("Admin user '{$username}' password updated.");

        return self::SUCCESS;
    }
}
