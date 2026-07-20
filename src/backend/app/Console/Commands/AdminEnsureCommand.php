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
                            {--password= : Admin password (required unless env ADMIN_PASSWORD)}
                            {--email=admin@localhost : Admin email}';

    protected $description = 'Ensure the admin user exists with the given password';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        $email = (string) $this->option('email');
        $password = $this->option('password') ?: env('ADMIN_PASSWORD');

        if (! $password) {
            $this->error('Password required via --password or ADMIN_PASSWORD env');

            return self::FAILURE;
        }

        $user = User::query()->where('username', $username)->orWhere('email', $email)->first();
        if (! $user) {
            $user = new User;
            $user->username = $username;
            $user->email = $email;
            $user->name = $username;
        }

        $passwordChanged = ! $user->exists
            || ! Hash::check($password, $user->getOriginal('password') ?? $user->password);

        $user->username = $username;
        $user->email = $email;
        $user->name = $user->name ?: $username;
        $user->password = Hash::make($password);
        $user->save();

        if ($passwordChanged) {
            app(LoginProtectionService::class)->clearAll();
        }

        $this->info("Admin user '{$username}' ensured.");

        return self::SUCCESS;
    }
}
