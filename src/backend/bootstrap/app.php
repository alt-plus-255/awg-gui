<?php

use App\Http\Middleware\EnsureApiAuthenticated;
use App\Http\Middleware\EnsureSpaStateful;
use App\Http\Middleware\SetLocale;
use App\Support\ApiHttpErrorMessage;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global: locale for unmatched routes (404) and early exception rendering
        $middleware->prepend(SetLocale::class);
        $middleware->api(prepend: [
            EnsureSpaStateful::class,
            SetLocale::class,
        ]);
        $middleware->appendToGroup('api', [
            EnsureApiAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            SetLocale::apply($request);

            if ($e instanceof ValidationException) {
                // Let Laravel render with the locale applied above.
                return null;
            }

            if ($e instanceof AuthenticationException) {
                return response()->json([
                    'message' => __('api.unauthenticated'),
                    'error' => 'unauthenticated',
                ], 401);
            }

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();

                return response()->json([
                    'message' => ApiHttpErrorMessage::for($e),
                    'error' => match ($status) {
                        401 => 'unauthenticated',
                        403 => 'forbidden',
                        404 => 'not_found',
                        405 => 'method_not_allowed',
                        419 => 'page_expired',
                        429 => 'too_many_requests',
                        503 => 'service_unavailable',
                        default => 'http_error',
                    },
                ], $status, $e->getHeaders());
            }

            if (config('app.debug')) {
                return null;
            }

            return response()->json([
                'message' => __('api.server_error'),
                'error' => 'server_error',
                'debug' => [
                    'exception' => get_class($e),
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => array_slice(
                        array_map(
                            fn (array $f) => ($f['file'] ?? '') . ':' . ($f['line'] ?? '') . ' ' . ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? ''),
                            $e->getTrace()
                        ),
                        0, 15
                    ),
                ],
            ], 500);
        });
    })->create();
