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

    public function store(Request $request): RedirectResponse
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
        ], [
            'required' => 'Заполните поле «:attribute».',
            'email.email' => 'Введите корректный адрес электронной почты.',
            'email.unique' => 'Этот email уже зарегистрирован. Войдите или восстановите доступ.',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.min' => 'Пароль должен содержать не менее :min символов.',
            'password.letters' => 'Пароль должен содержать хотя бы одну букву.',
            'password.numbers' => 'Пароль должен содержать хотя бы одну цифру.',
            'tree_slug.alpha_dash' => 'Адрес может содержать только латинские буквы, цифры, дефис и подчёркивание.',
            'tree_slug.ascii' => 'Для адреса используйте только латинские символы.',
            'tree_slug.not_in' => 'Этот адрес зарезервирован системой.',
            'tree_slug.unique' => 'Этот адрес дерева уже занят.',
        ], [
            'name' => 'Ваше имя',
            'email' => 'Email',
            'password' => 'Пароль',
            'tree_name' => 'Название семьи',
            'tree_slug' => 'Адрес дерева',
        ]);
        if ($validator->fails()) {
            $response = back()->withErrors($validator)->withInput($request->except(['password', 'password_confirmation']));
            if ($validator->errors()->has('tree_slug')) {
                $response->with('slug_suggestions', $this->availableSlugs($request->string('tree_slug')->toString()));
            }

            return $response;
        }
        $data = $validator->validated();

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
