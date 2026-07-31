<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminEnsureCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_when_missing(): void
    {
        $this->artisan('admin:ensure', [
            '--username' => 'admin',
            '--password' => 'initial-secret',
            '--email' => 'admin@localhost',
        ])->assertSuccessful();

        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('initial-secret', $user->password));
    }

    public function test_preserves_existing_password_on_upgrade(): void
    {
        User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@localhost',
            'password' => 'user-changed-password',
        ]);

        $this->artisan('admin:ensure', [
            '--username' => 'admin',
            '--password' => 'env-install-password',
            '--email' => 'admin@localhost',
        ])->assertSuccessful();

        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('user-changed-password', $user->password));
        $this->assertFalse(Hash::check('env-install-password', $user->password));
    }

    public function test_force_password_overwrites_existing_password(): void
    {
        User::factory()->create([
            'username' => 'admin',
            'email' => 'admin@localhost',
            'password' => 'old-password',
        ]);

        $this->artisan('admin:ensure', [
            '--username' => 'admin',
            '--password' => 'new-password',
            '--email' => 'admin@localhost',
            '--force-password' => true,
        ])->assertSuccessful();

        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('new-password', $user->password));
    }
}
