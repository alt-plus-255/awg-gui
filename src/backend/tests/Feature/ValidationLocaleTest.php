<?php

namespace Tests\Feature;

use Tests\TestCase;

class ValidationLocaleTest extends TestCase
{
    public function test_accept_language_header_switches_validation_locale(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'ru',
        ])->postJson('/api/login', [
            'username' => '',
            'password' => '',
        ]);

        $response->assertStatus(422);
        $this->assertSame('Поле имя пользователя обязательно.', $response->json('errors.username.0'));
    }

    public function test_english_accept_language_keeps_english_validation(): void
    {
        $response = $this->withHeaders([
            'Accept' => 'application/json',
            'Accept-Language' => 'en',
        ])->postJson('/api/login', [
            'username' => '',
            'password' => '',
        ]);

        $response->assertStatus(422);
        $this->assertSame('The username field is required.', $response->json('errors.username.0'));
    }
}
