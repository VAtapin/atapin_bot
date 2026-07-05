<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\AnalyticsService;
use App\Services\OwnerPersonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        abort_unless(PlatformSetting::value('registration_enabled', true), 403);

        return view('public.register');
    }

    public function store(Request $request, AnalyticsService $analytics): RedirectResponse
    {
        abort_unless(PlatformSetting::value('registration_enabled', true), 403);

        $request->merge([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'tree_slug' => Str::lower(trim((string) $request->input('tree_slug'))),
        ]);
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()],
            'tree_name' => ['required', 'string', 'max:150'],
            'tree_slug' => [
                'required',
                'string',
                'max:80',
                'alpha_dash:ascii',
                Rule::notIn(['person', 'login', 'register', 'admin', 'manage', 'api']),
                'unique:family_trees,slug',
            ],
            'privacy_consent' => ['accepted'],
        ], [
            'required' => __('public.messages.required'),
            'email.email' => __('public.messages.email'),
            'email.unique' => __('public.messages.email_unique'),
            'password.confirmed' => __('public.messages.password_confirmed'),
            'password.min' => __('public.messages.password_min'),
            'password.letters' => __('public.messages.password_letters'),
            'tree_slug.alpha_dash' => __('public.messages.slug_format'),
            'tree_slug.ascii' => __('public.messages.slug_ascii'),
            'tree_slug.not_in' => __('public.messages.slug_reserved'),
            'tree_slug.unique' => __('public.messages.slug_unique'),
            'privacy_consent.accepted' => __('public.auth.privacy_required'),
        ], [
            'name' => __('public.auth.name'),
            'email' => __('public.common.email'),
            'password' => __('public.common.password'),
            'tree_name' => __('public.auth.tree_name'),
            'tree_slug' => __('public.auth.tree_slug'),
            'privacy_consent' => __('public.auth.privacy_link'),
        ]);
        if ($validator->fails()) {
            $response = back()->withErrors($validator)->withInput($request->except(['password', 'password_confirmation']));
            if ($validator->errors()->has('tree_slug')) {
                $response->with('slug_suggestions', $this->availableSlugs($request->string('tree_slug')->toString()));
            }

            return $response;
        }
        $data = $validator->validated();
        $privacyIpHash = $analytics->hashIp($request->ip());

        [$user, $tree] = DB::transaction(function () use ($data, $privacyIpHash): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'is_active' => true,
                'two_factor_enabled' => false,
                'two_factor_required' => false,
                'privacy_accepted_at' => now(),
                'privacy_policy_version' => (string) config('privacy.policy_version'),
                'privacy_ip_hash' => $privacyIpHash,
            ]);
            $plan = Plan::query()->where('code', 'family')->first();
            $tree = FamilyTree::query()->create([
                'owner_user_id' => $user->id,
                'plan_id' => $plan?->id,
                'name' => $data['tree_name'],
                'slug' => Str::lower($data['tree_slug']),
                'subtitle' => __('public.tagline'),
                'status' => 'active',
                'trial_ends_at' => now()->addDays(30),
            ]);
            app(OwnerPersonService::class)->ensure($tree, $user);
            if ($plan) {
                Subscription::query()->create([
                    'tree_id' => $tree->id,
                    'plan_id' => $plan->id,
                    'status' => 'trial',
                    'amount' => $plan->price_monthly,
                    'currency' => $plan->currency,
                    'starts_at' => now(),
                    'ends_at' => $tree->trial_ends_at,
                ]);
            }
            $user->updateQuietly(['last_tree_id' => $tree->id]);

            return [$user, $tree];
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('family_tree_id', $tree->id);
        $analytics->linkUser($request, $user);
        $analytics->record('sign_up', $request, $user, $tree, [
            'tree_id' => $tree->id,
            'method' => 'password',
        ], "sign_up:user:{$user->id}", true);
        $analytics->record('family_tree_created', $request, $user, $tree, [
            'tree_id' => $tree->id,
            'plan_id' => $tree->plan_id,
        ], "family_tree_created:tree:{$tree->id}", true);

        return redirect()->route('account', ['welcome' => 1]);
    }

    private function availableSlugs(string $requested): array
    {
        $base = Str::slug($requested) ?: 'family';

        return collect(range(2, 20))
            ->map(fn (int $suffix): string => $base.'-'.$suffix)
            ->reject(fn (string $slug): bool => FamilyTree::withTrashed()->where('slug', $slug)->exists())
            ->take(3)
            ->values()
            ->all();
    }
}
