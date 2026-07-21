<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['ru', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale($request);
        App::setLocale($locale);

        return $next($request);
    }

    private function resolveLocale(Request $request): string
    {
        $header = (string) $request->header('Accept-Language', '');
        if ($header === '') {
            return (string) config('app.locale');
        }

        // Parse "ru-RU,ru;q=0.9,en;q=0.8" → ordered language tags
        $parts = array_map('trim', explode(',', $header));
        foreach ($parts as $part) {
            $tag = strtolower(trim(explode(';', $part, 2)[0]));
            if ($tag === '') {
                continue;
            }
            $primary = explode('-', $tag, 2)[0];
            if (in_array($primary, self::SUPPORTED, true)) {
                return $primary;
            }
        }

        return (string) config('app.locale');
    }
}
