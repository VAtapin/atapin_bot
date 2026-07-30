<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Services\AnalyticsService;
use App\Services\AuthRedirector;
use App\Support\FamilyTreeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

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
        $tree = $request->attributes->get('familyTree');
        if (! $tree && $request->session()->has('family_tree_id')) {
            $tree = FamilyTree::query()->find($request->session()->get('family_tree_id'));
        }

        try {
            $analytics->record('logout', $request, $request->user(), $tree instanceof FamilyTree ? $tree : null);
        } catch (\Throwable $exception) {
            report($exception);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Cookie::queue(Cookie::forget((string) config('session.cookie')));
        Cookie::queue(Cookie::forget('XSRF-TOKEN'));

        if ($domain = config('session.domain')) {
            Cookie::queue(Cookie::forget((string) config('session.cookie'), '/', $domain));
            Cookie::queue(Cookie::forget('XSRF-TOKEN', '/', $domain));
        }

        $response = $tree instanceof FamilyTree
            ? redirect()->to(app(FamilyTreeUrl::class)->tree($tree))
            : redirect()->to(rtrim((string) config('app.url'), '/').'/'.app()->getLocale());

        return $response;
    }
}
