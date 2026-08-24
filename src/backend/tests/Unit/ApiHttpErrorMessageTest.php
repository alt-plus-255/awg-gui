<?php

namespace Tests\Unit;

use App\Support\ApiHttpErrorMessage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ApiHttpErrorMessageTest extends TestCase
{
    #[Test]
    public function it_localizes_framework_route_not_found_message_in_russian(): void
    {
        $this->app->setLocale('ru');

        $message = ApiHttpErrorMessage::for(new NotFoundHttpException(
            'The route api/settings/test-telegram-proxy could not be found.'
        ));

        $this->assertSame('Запрошенный ресурс не найден.', $message);
    }

    #[Test]
    public function it_keeps_custom_abort_messages(): void
    {
        $this->app->setLocale('en');

        $message = ApiHttpErrorMessage::for(new NotFoundHttpException('Custom peer missing'));

        $this->assertSame('Custom peer missing', $message);
    }
}
