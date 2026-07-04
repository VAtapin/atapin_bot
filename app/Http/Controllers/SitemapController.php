<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Support\PublicSeo;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $pages = CmsPage::query()
            ->where('is_published', true)
            ->get(['locale', 'slug', 'updated_at']);
        $urls = collect(PublicSeo::LOCALES)->flatMap(function (string $locale) use ($pages): array {
            $static = [
                ['loc' => PublicSeo::localizedUrl($locale, route('home')), 'priority' => '1.0'],
                ['loc' => PublicSeo::localizedUrl($locale, route('faq')), 'priority' => '0.7'],
                ['loc' => PublicSeo::localizedUrl($locale, route('register')), 'priority' => '0.8'],
            ];
            $localizedPages = $pages
                ->where('locale', $locale)
                ->map(fn (CmsPage $page): array => [
                    'loc' => PublicSeo::localizedUrl($locale, route('public.page', $page->slug)),
                    'lastmod' => $page->updated_at?->toAtomString(),
                    'priority' => '0.6',
                ])
                ->values()
                ->all();

            return [...$static, ...$localizedPages];
        });

        return response()
            ->view('public.sitemap', compact('urls'))
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
