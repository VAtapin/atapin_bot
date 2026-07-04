<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\AuthRedirector;
use App\Support\CurrentTree;
use App\Support\SafeReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PublicAuthController extends Controller
{
    public function create(Request $request, AuthRedirector $redirector): View|RedirectResponse
    {
        if ($request->user()) {
            return $redirector->redirect(
                $request->user(),
                $this->requestedTree($request),
            );
        }

        return view('public.login', [
            'tree' => $this->requestedTree($request),
        ]);
    }

    public function store(Request $request, AuthRedirector $redirector, AnalyticsService $analytics): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'tree_slug' => ['nullable', 'string', 'max:80'],
            'return_to' => ['nullable', 'string', 'max:2000'],
        ]);
        $tree = $this->requestedTree($request);
        $key = Str::lower($data['login']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'login' => __('public.messages.too_many', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = User::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query
                ->where('email', Str::lower($data['login']))
                ->orWhere('login', $data['login']))
            ->first();
        $authenticated = $user && Hash::check($data['password'], $user->password);

        if ($authenticated) {
            Auth::login($user, $request->boolean('remember'));
        }

        if (! $authenticated && $tree) {
            app(CurrentTree::class)->set($tree);
            $person = Person::query()
                ->where('login', $data['login'])
                ->where('web_login_enabled', true)
                ->first();

            if ($person && $person->password && Hash::check($data['password'], $person->password)) {
                $membership = TreeMembership::query()
                    ->where('tree_id', $tree->id)
                    ->where('person_id', $person->id)
                    ->where('status', 'approved')
                    ->first();

                if ($membership) {
                    Auth::login($membership->user, $request->boolean('remember'));
                    $authenticated = true;
                }
            }
        }

        if (! $authenticated) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'login' => __('public.messages.invalid_login'),
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('family_user_id', Auth::id());
        $analytics->linkUser($request, $request->user());
        $analytics->record('login', $request, $request->user(), $tree, [
            'method' => 'password',
            'tree_id' => $tree?->id,
        ], null, true);

        if (! $request->user()->privacy_accepted_at) {
            $request->session()->put('privacy_return_tree_id', $tree?->id);

            return redirect()->route('privacy-consent.show');
        }

        if (
            ($returnTo = SafeReturnUrl::path($data['return_to'] ?? null))
            && (
                $request->user()->is_super_admin
                || ($tree && $request->user()->approvedMembershipFor($tree))
            )
        ) {
            return redirect()->to($returnTo);
        }

        return $redirector->redirect($request->user(), $tree);
    }

    public function destroy(Request $request, AnalyticsService $analytics): RedirectResponse
    {
        $analytics->record('logout', $request, $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function requestedTree(Request $request): ?FamilyTree
    {
        $slug = $request->string('tree_slug')->toString()
            ?: $request->string('tree')->toString();

        return $slug === ''
            ? null
            : FamilyTree::query()->where('slug', $slug)->where('status', 'active')->first();
    }
}
