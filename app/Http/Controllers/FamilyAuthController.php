<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Services\AnalyticsService;
use App\Services\AuthRedirector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Совместимость со старыми формами. Новые формы используют PublicAuthController.
 */
class FamilyAuthController extends Controller
{
    public function login(
        Request $request,
        AuthRedirector $redirector,
        AnalyticsService $analytics,
    ): RedirectResponse
    {
        if (! $request->filled('tree_slug') && $request->session()->has('family_tree_id')) {
            $slug = FamilyTree::query()
                ->whereKey($request->session()->get('family_tree_id'))
                ->value('slug');
            $request->merge(['tree_slug' => $slug]);
        }

        return app(PublicAuthController::class)->store($request, $redirector, $analytics);
    }

    public function logout(Request $request, AnalyticsService $analytics): RedirectResponse
    {
        return app(PublicAuthController::class)->destroy($request, $analytics);
    }
}
