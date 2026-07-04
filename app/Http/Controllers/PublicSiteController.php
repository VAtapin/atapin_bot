<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicSiteController extends Controller
{
    public function home(Request $request): View
    {
        if ($tree = $request->attributes->get('customDomainTree')) {
            return app(MiniAppController::class)->index($request, $tree);
        }

        return view('public.home', [
            'plans' => Plan::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'footerPages' => $this->footerPages(),
        ]);
    }

    public function page(string $slug): View
    {
        $page = $this->findPage($slug, false);

        return view('public.page', [
            'page' => $page,
            'footerPages' => $this->footerPages(),
        ]);
    }

    public function preview(string $slug): View
    {
        abort_unless(auth()->user()?->is_super_admin, 403);
        $page = $this->findPage($slug, true);

        return view('public.page', [
            'page' => $page,
            'footerPages' => $this->footerPages(),
        ]);
    }

    private function findPage(string $slug, bool $includeDrafts): CmsPage
    {
        $query = CmsPage::query()
            ->where('slug', $slug)
            ->whereIn('locale', [app()->getLocale(), 'ru'])
            ->when(! $includeDrafts, fn ($builder) => $builder->where('is_published', true))
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [app()->getLocale()]);

        return $query->firstOrFail();
    }

    private function footerPages()
    {
        return CmsPage::query()
            ->where('is_published', true)
            ->whereIn('locale', [app()->getLocale(), 'ru'])
            ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [app()->getLocale()])
            ->orderBy('sort_order')
            ->get(['locale', 'slug', 'title'])
            ->unique('slug')
            ->values();
    }
}
