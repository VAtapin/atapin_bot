<?php

namespace App\Support;

use Illuminate\Http\Request;

class PublicSeo
{
    /** @var list<string> */
    public const LOCALES = ['ru', 'de', 'en', 'uk'];

    public static function localizedUrl(string $locale, ?string $url = null): string
    {
        $url ??= url()->current();
        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $query['lang'] = $locale;

        $base = ($parts['scheme'] ?? request()->getScheme()).'://'.($parts['host'] ?? request()->getHost());
        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }
        $base .= $parts['path'] ?? '/';

        return $base.'?'.http_build_query($query);
    }

    public static function canonical(Request $request): string
    {
        return self::localizedUrl(app()->getLocale(), $request->url());
    }

    public static function ogLocale(string $locale): string
    {
        return match ($locale) {
            'de' => 'de_DE',
            'en' => 'en_US',
            'uk' => 'uk_UA',
            default => 'ru_RU',
        };
    }
}
