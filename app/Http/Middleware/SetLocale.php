<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['ru', 'de', 'en', 'uk'];

    public function handle(Request $request, Closure $next): Response
    {
        $requested = mb_strtolower((string) $request->query('lang', ''));
        $stored = mb_strtolower((string) ($request->session()->get('locale') ?: $request->cookie('locale')));

        if (in_array($requested, self::SUPPORTED, true)) {
            $locale = $requested;
            $request->session()->put('locale', $locale);
            Cookie::queue(cookie()->forever('locale', $locale, null, null, $request->isSecure(), false, false, 'lax'));
        } elseif (in_array($stored, self::SUPPORTED, true)) {
            $locale = $stored;
        } else {
            $locale = $request->getPreferredLanguage(self::SUPPORTED) ?: (string) config('app.locale', 'ru');
        }

        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'ru');

        return $next($request);
    }
}
