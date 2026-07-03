<?php

namespace App\Http\Controllers;

use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TotpController extends Controller
{
    public function setup(Request $request, TotpService $totp): View
    {
        $secret = (string) $request->session()->get('totp_setup_secret');
        if ($secret === '') {
            $secret = $totp->generateSecret();
            $request->session()->put('totp_setup_secret', $secret);
        }

        return view('public.totp-setup', [
            'secret' => $secret,
            'qrCode' => $totp->qrCode($request->user()->email, $secret),
        ]);
    }

    public function confirm(Request $request, TotpService $totp): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = (string) $request->session()->get('totp_setup_secret');
        $counter = $secret === '' ? false : $totp->verify($secret, $data['code']);

        if ($counter === false) {
            throw ValidationException::withMessages([
                'code' => 'Неверный код. Проверьте время на телефоне и попробуйте ещё раз.',
            ]);
        }

        $request->user()->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_counter' => $counter,
        ])->save();
        $request->session()->forget('totp_setup_secret');

        $redirectTo = (string) $request->session()->pull('two_factor_intended_url');

        return redirect()->to($redirectTo !== '' ? $redirectTo : route('account'))
            ->with('status', 'Приложение-аутентификатор подключено.');
    }

    public function destroy(Request $request, TotpService $totp): RedirectResponse
    {
        abort_if(
            $request->user()->is_super_admin || $request->user()->two_factor_required,
            422,
            'Администратор сделал двухфакторную защиту обязательной для этой учётной записи.',
        );

        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $counter = $totp->verify(
            (string) $request->user()->two_factor_secret,
            $data['code'],
            $request->user()->two_factor_last_used_counter,
        );

        if ($counter === false) {
            throw ValidationException::withMessages(['code' => 'Неверный код подтверждения.']);
        }

        $request->user()->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_counter' => null,
        ])->save();

        return back()->with('status', 'Приложение-аутентификатор отключено.');
    }
}
