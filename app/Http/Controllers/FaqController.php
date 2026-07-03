<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\FaqCategory;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function __invoke(): View
    {
        return view('public.faq', [
            'categories' => FaqCategory::query()
                ->where('is_published', true)
                ->whereHas('items', fn ($query) => $query->where('is_published', true))
                ->with(['items' => fn ($query) => $query->where('is_published', true)])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'footerPages' => CmsPage::query()
                ->where('is_published', true)
                ->orderBy('sort_order')
                ->get(['slug', 'title']),
        ]);
    }
}
