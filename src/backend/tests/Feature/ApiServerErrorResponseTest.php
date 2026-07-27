<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiServerErrorResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_production_500_does_not_include_debug_payload(): void
    {
        config(['app.debug' => false]);

        Route::middleware('api')->get('/api/__test_boom', function () {
            throw new \RuntimeException('secret-internal-detail');
        });

        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/__test_boom');

        $response->assertStatus(500)
            ->assertJson([
                'error' => 'server_error',
            ])
            ->assertJsonMissingPath('debug');

        $this->assertStringNotContainsString('secret-internal-detail', $response->getContent());
    }
}
