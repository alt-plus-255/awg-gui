<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-party SPA is always served from the same panel host.
 * Force Sanctum stateful session/CSRF stack so /api/login never hits
 * "Session store not set on request" when Origin/Referer is missing or exotic.
 */
class EnsureSpaStateful
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHttpHost();
        $origin = $request->getScheme().'://'.$host;

        // Prefer the real request host for matching, even if browser sent another Origin.
        $request->headers->set('Origin', $origin);
        if (! $request->headers->get('referer')) {
            $request->headers->set('Referer', $origin.'/');
        }

        $stateful = array_values(array_unique(array_filter(array_merge(
            config('sanctum.stateful', []),
            [
                $host,
                Str::before($host, ':'),
                Sanctum::$currentRequestHostPlaceholder,
            ]
        ))));
        config(['sanctum.stateful' => $stateful]);

        // Always run the SPA cookie stack (do not depend on fromFrontend heuristics).
        return (new Pipeline(app()))->send($request)->through([
            function ($request, $next) {
                $request->attributes->set('sanctum', true);

                return $next($request);
            },
            config('sanctum.middleware.encrypt_cookies', \Illuminate\Cookie\Middleware\EncryptCookies::class),
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            config(
                'sanctum.middleware.validate_csrf_token',
                config('sanctum.middleware.verify_csrf_token', \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ),
            config('sanctum.middleware.authenticate_session'),
        ])->then(fn ($request) => $next($request));
    }
}
