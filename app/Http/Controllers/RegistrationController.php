<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Models\TreeMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('public.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', 'min:10'],
            'tree_name' => ['required', 'string', 'max:150'],
            'tree_slug' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:family_trees,slug'],
        ]);

        [$user, $tree] = DB::transaction(function () use ($data): array {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'is_active' => true,
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

            return [$user, $tree];
        });

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('family_tree_id', $tree->id);

        return redirect('/admin');
    }
}
