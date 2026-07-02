<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\TreeMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        abort_unless(PlatformSetting::value('registration_enabled', true), 403);

        return view('public.register');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(PlatformSetting::value('registration_enabled', true), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:10'],
            'tree_name' => ['required', 'string', 'max:150'],
            'tree_slug' => [
                'required',
                'string',
                'max:80',
                'alpha_dash:ascii',
                Rule::notIn(['person', 'login', 'register', 'admin', 'manage', 'api']),
                'unique:family_trees,slug',
            ],
        ]);

        [$user, $tree] = DB::transaction(function () use ($data): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'is_active' => true,
                'two_factor_enabled' => (bool) config('platform.require_owner_two_factor', true),
            ]);
            $plan = Plan::query()->where('code', 'family')->first();
            $tree = FamilyTree::query()->create([
                'owner_user_id' => $user->id,
                'plan_id' => $plan?->id,
                'name' => $data['tree_name'],
                'slug' => Str::lower($data['tree_slug']),
                'subtitle' => 'Семейная история и память рода',
                'status' => 'active',
                'trial_ends_at' => now()->addDays(30),
            ]);
            TreeMembership::query()->create([
                'tree_id' => $tree->id,
                'user_id' => $user->id,
                'role' => 'owner',
                'status' => 'approved',
                'approved_by_user_id' => $user->id,
                'approved_at' => now(),
            ]);
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

        return redirect('/manage/'.$tree->slug);
    }
}
