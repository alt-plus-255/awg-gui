<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ApiHttpErrorMessage
{
    public static function for(HttpExceptionInterface $e): string
    {
        $status = $e->getStatusCode();
        $raw = trim((string) $e->getMessage());

        if ($raw !== '' && ! self::isFrameworkDefault($raw, $status)) {
            return $raw;
        }

        $key = 'api.http_'.$status;
        $translated = __($key);
        if ($translated !== $key) {
            return $translated;
        }

        return __('api.http_error', ['status' => $status]);
    }

    private static function isFrameworkDefault(string $message, int $status): bool
    {
        if (preg_match('/^The route .+ could not be found\.?$/i', $message) === 1) {
            return true;
        }

        if (preg_match('/^The .+ method is not supported for (this )?route/i', $message) === 1) {
            return true;
        }

        $normalized = rtrim($message, '.');
        $defaults = [
            401 => ['Unauthenticated', 'Unauthorized'],
            403 => ['Forbidden', 'This action is unauthorized', 'Access denied'],
            404 => ['Not Found', 'Not found'],
            405 => ['Method Not Allowed'],
            419 => ['Page Expired', 'CSRF token mismatch'],
            429 => ['Too Many Attempts', 'Too Many Requests'],
            503 => ['Service Unavailable'],
        ];

        foreach ($defaults[$status] ?? [] as $default) {
            if (strcasecmp($normalized, $default) === 0) {
                return true;
            }
        }

        foreach (['Unauthenticated', 'Unauthorized', 'Forbidden', 'Not Found', 'Too Many Attempts', 'Too Many Requests'] as $default) {
            if (strcasecmp($normalized, $default) === 0) {
                return true;
            }
        }

        return false;
    }
}
