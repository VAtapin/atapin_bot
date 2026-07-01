<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $request->session()->forget('family_telegram_user_id');

        return redirect()->route('family.app');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget([
            'family_person_id',
            'family_telegram_user_id',
        ]);
        $request->session()->regenerateToken();

        return redirect()->route('family.app');
    }
}
