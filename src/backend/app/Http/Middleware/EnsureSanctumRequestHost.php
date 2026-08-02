<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-party SPA is always same-origin behind Caddy.
 * Keep Sanctum stateful matching aligned with the real Host (incl. :7443)
 * even when Origin/Referer are missing or SANCTUM_STATEFUL_DOMAINS is stale.
 */
class EnsureSanctumRequestHost
{
    public function handle(Request $request, Closure $next): Response
    {
        self::align($request);

        return $next($request);
    }

    public static function align(Request $request): void
    {
        $host = $request->getHttpHost();
        $origin = $request->getScheme().'://'.$host;

        if (! $request->headers->get('Origin')) {
            $request->headers->set('Origin', $origin);
        }
        if (! $request->headers->get('Referer')) {
            $request->headers->set('Referer', $origin.'/');
        }

        $bare = Str::before($host, ':');
        $stateful = array_values(array_unique(array_filter(array_merge(
            config('sanctum.stateful', []),
            [
                $host,
                $bare,
                Sanctum::$currentRequestHostPlaceholder,
            ]
        ))));
        config(['sanctum.stateful' => $stateful]);
    }
}
