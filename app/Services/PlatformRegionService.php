<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PlatformRegionService
{
    public function regionForRequest(Request $request): string
    {
        return $this->regionForHost($request->getHost());
    }

    public function regionForHost(?string $host): string
    {
        $host = Str::lower((string) $host);
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;

        $ruDomains = array_filter([
            config('platform.locale_domains.ru'),
            config('platform.locale_domains.ru_cyrillic'),
        ]);

        foreach ($ruDomains as $domain) {
            $domain = Str::lower((string) $domain);
            if ($host === $domain || Str::endsWith($host, '.'.$domain)) {
                return 'ru';
            }
        }

        return 'eu';
    }

    public function currencyForRegion(string $region): string
    {
        return $region === 'ru' ? 'RUB' : 'EUR';
    }

    public function defaultLocaleForRegion(string $region): string
    {
        return $region === 'ru' ? 'ru' : 'ru';
    }
}
