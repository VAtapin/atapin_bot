<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\TreeMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class FamilyAuthController extends Controller
{
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $person = Person::query()
            ->where('login', $credentials['login'])
            ->where('web_login_enabled', true)
            ->first();

        if (! $person || ! $person->password || ! Hash::check($credentials['password'], $person->password)) {
            throw ValidationException::withMessages([
                'login' => 'Неверный логин или пароль.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('family_person_id', $person->id);
        $request->session()->put('family_tree_id', $person->tree_id);
        $request->session()->forget('family_telegram_user_id');
        $membership = TreeMembership::query()
            ->where('tree_id', $person->tree_id)
            ->where('person_id', $person->id)
            ->where('status', 'approved')
            ->first();
        if ($membership) {
            Auth::login($membership->user);
            $request->session()->put('family_user_id', $membership->user_id);
        }

        return redirect()->route('family.app');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->forget([
            'family_person_id',
            'family_telegram_user_id',
            'family_user_id',
            'family_tree_id',
        ]);
        $request->session()->regenerateToken();

        return redirect()->route('family.app');
    }
}
