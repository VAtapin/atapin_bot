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
            'footerPages' => CmsPage::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->get(['slug', 'title']),
        ]);
    }

    public function page(CmsPage $page): View
    {
        abort_unless($page->is_published, 404);

        return view('public.page', [
            'page' => $page,
            'footerPages' => CmsPage::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->get(['slug', 'title']),
        ]);
    }

    public function preview(CmsPage $page): View
    {
        abort_unless(auth()->user()?->is_super_admin, 403);

        return view('public.page', [
            'page' => $page,
            'footerPages' => CmsPage::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->get(['slug', 'title']),
        ]);
    }
}
