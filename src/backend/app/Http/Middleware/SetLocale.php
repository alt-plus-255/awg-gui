<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    public const SUPPORTED = ['ru', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        self::apply($request);

        return $next($request);
    }

    public static function apply(?Request $request = null): string
    {
        $locale = self::resolve($request ?? request());
        App::setLocale($locale);

        return $locale;
    }

    public static function resolve(?Request $request = null): string
    {
        $request ??= request();
        $header = (string) $request->header('Accept-Language', '');
        if ($header === '') {
            return (string) config('app.locale', 'en');
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

        return (string) config('app.locale', 'en');
    }
}
