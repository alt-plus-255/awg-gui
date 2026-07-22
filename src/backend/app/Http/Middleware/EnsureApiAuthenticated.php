<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Default-deny for the API: every route requires an authenticated session
 * except login endpoints needed to establish / inspect the pre-auth state.
 */
class EnsureApiAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isPublic($request)) {
            return $next($request);
        }

        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthenticated.'], 401);
    }

    private function isPublic(Request $request): bool
    {
        if ($request->isMethod('POST') && $request->is('api/login')) {
            return true;
        }

        if ($request->isMethod('GET') && $request->is('api/login/status')) {
            return true;
        }

        if ($request->isMethod('GET') && $request->is('api/login/info')) {
            return true;
        }

        if ($request->isMethod('GET') && $request->is('api/login/captcha')) {
            return true;
        }

        return false;
    }
}
