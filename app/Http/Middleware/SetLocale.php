<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /** @var list<string> */
    private const SUPPORTED = ['ru', 'de', 'en', 'uk'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($redirect = $this->localizedDomainRedirect($request)) {
            return $redirect;
        }

        $routeLocale = mb_strtolower((string) $request->route('locale', ''));
        $requested = mb_strtolower((string) $request->query('lang', ''));
        $stored = mb_strtolower((string) ($request->session()->get('locale') ?: $request->cookie('locale')));

        if (in_array($routeLocale, self::SUPPORTED, true)) {
            $locale = $routeLocale;
            $request->session()->put('locale', $locale);
            Cookie::queue(cookie()->forever('locale', $locale, null, null, $request->isSecure(), false, false, 'lax'));
        } elseif (in_array($requested, self::SUPPORTED, true)) {
            $locale = $requested;
            $request->session()->put('locale', $locale);
            Cookie::queue(cookie()->forever('locale', $locale, null, null, $request->isSecure(), false, false, 'lax'));
        } elseif (in_array($stored, self::SUPPORTED, true)) {
            $locale = $stored;
        } else {
            $locale = $request->getPreferredLanguage(self::SUPPORTED) ?: (string) config('app.locale', 'ru');
        }

        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'ru');
        URL::defaults(['locale' => App::getLocale()]);
        if ($request->route()?->hasParameter('locale')) {
            $request->route()->forgetParameter('locale');
        }

        return $next($request);
    }

    private function localizedDomainRedirect(Request $request): ?Response
    {
        $host = mb_strtolower($request->getHost());
        $canonicalHost = mb_strtolower((string) config('platform.domains.international'));
        $domains = (array) config('platform.locale_domains', []);
        $locale = match ($host) {
            mb_strtolower((string) ($domains['de'] ?? '')) => 'de',
            mb_strtolower((string) ($domains['ru'] ?? '')),
            mb_strtolower((string) ($domains['ru_cyrillic'] ?? '')) => 'ru',
            default => null,
        };

        if (! $locale || $canonicalHost === '' || $host === $canonicalHost) {
            return null;
        }

        $path = '/'.ltrim($request->path(), '/');
        $path = preg_replace('#^/(ru|de|en|uk)(?=/|$)#', '', $path) ?: '/';
        $localizedPath = "/{$locale}".($path === '/' ? '' : $path);
        $query = $request->query();
        unset($query['lang']);
        $url = 'https://'.$canonicalHost.$localizedPath.($query ? '?'.http_build_query($query) : '');

        return redirect()->away($url, 301);
    }
}
