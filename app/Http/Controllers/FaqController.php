<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\FaqCategory;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function __invoke(): View
    {
        $locale = FaqCategory::query()->where('locale', app()->getLocale())->exists()
            ? app()->getLocale()
            : 'ru';

        return view('public.faq', [
            'categories' => FaqCategory::query()
                ->where('locale', $locale)
                ->where('is_published', true)
                ->whereHas('items', fn ($query) => $query->where('is_published', true))
                ->with(['items' => fn ($query) => $query->where('is_published', true)])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'footerPages' => CmsPage::query()
                ->where('is_published', true)
                ->whereIn('locale', [app()->getLocale(), 'ru'])
                ->orderByRaw('CASE WHEN locale = ? THEN 0 ELSE 1 END', [app()->getLocale()])
                ->orderBy('sort_order')
                ->get(['locale', 'slug', 'title'])
                ->unique('slug')
                ->values(),
        ]);
    }
}
