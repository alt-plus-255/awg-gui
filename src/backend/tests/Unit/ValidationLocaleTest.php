<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ValidationLocaleTest extends TestCase
{
    public function test_russian_required_message_uses_translated_attribute(): void
    {
        App::setLocale('ru');

        $validator = Validator::make(['name' => ''], ['name' => ['required']]);

        $this->assertTrue($validator->fails());
        $this->assertSame('Поле имя обязательно.', $validator->errors()->first('name'));
    }

    public function test_english_required_message_stays_default(): void
    {
        App::setLocale('en');

        $validator = Validator::make(['name' => ''], ['name' => ['required']]);

        $this->assertTrue($validator->fails());
        $this->assertSame('The name field is required.', $validator->errors()->first('name'));
    }
}
