<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function show(): View
    {
        return view('public.two-factor');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $hash = (string) $request->session()->get('two_factor_code_hash');
        $expiresAt = (int) $request->session()->get('two_factor_expires_at');

        if ($expiresAt < now()->timestamp || ! Hash::check($data['code'], $hash)) {
            throw ValidationException::withMessages(['code' => 'Неверный или устаревший код.']);
        }

        $request->session()->put('two_factor_user_id', $request->user()->id);
        $request->session()->forget(['two_factor_code_hash', 'two_factor_expires_at']);

        return redirect()->to(
            (string) $request->session()->pull('two_factor_intended_url', '/trees'),
        );
    }
}
