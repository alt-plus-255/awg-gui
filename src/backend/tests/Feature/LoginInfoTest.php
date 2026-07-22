<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_info_is_public_and_excludes_password(): void
    {
        User::factory()->create([
            'username' => 'admin',
            'password' => 'secret-password',
        ]);

        $response = $this->getJson('/api/login/info');

        $response->assertOk()
            ->assertJsonStructure([
                'host',
                'port',
                'https_port',
                'panel_url',
                'ssl_enabled',
                'username',
            ])
            ->assertJson([
                'username' => 'admin',
            ]);

        $body = $response->json();
        $this->assertArrayNotHasKey('password', $body);
        $this->assertStringNotContainsString('secret-password', json_encode($body));
    }
}
