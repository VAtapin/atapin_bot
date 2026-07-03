<?php

namespace App\Http\Controllers;

use App\Services\TotpService;
use App\Services\TwoFactorCodeDelivery;
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

    public function verify(
        Request $request,
        TwoFactorCodeDelivery $delivery,
        TotpService $totp,
    ): RedirectResponse {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $hash = (string) $request->session()->get('two_factor_code_hash');
        $expiresAt = (int) $request->session()->get('two_factor_expires_at');
        $user = $request->user();
        $totpCounter = filled($user->two_factor_secret) && $user->two_factor_confirmed_at
            ? $totp->verify(
                $user->two_factor_secret,
                $data['code'],
                $user->two_factor_last_used_counter,
            )
            : false;
        $fallbackIsValid = $expiresAt >= now()->timestamp
            && $hash !== ''
            && Hash::check($data['code'], $hash);

        if ($totpCounter === false && ! $fallbackIsValid) {
            throw ValidationException::withMessages(['code' => 'Неверный или устаревший код.']);
        }

        if ($totpCounter !== false) {
            $user->forceFill(['two_factor_last_used_counter' => $totpCounter])->save();
        }
        $delivery->deleteServerFallback($user);
        $request->session()->put('two_factor_user_id', $user->id);
        $request->session()->forget(['two_factor_code_hash', 'two_factor_expires_at']);

        if ($user->two_factor_required && ! $user->two_factor_confirmed_at) {
            return redirect()->route('totp.setup');
        }

        return redirect()->to(
            (string) $request->session()->pull('two_factor_intended_url', '/trees'),
        );
    }
}
