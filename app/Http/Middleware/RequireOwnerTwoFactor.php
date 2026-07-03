<?php

namespace App\Http\Middleware;

use App\Services\TwoFactorCodeDelivery;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class RequireOwnerTwoFactor
{
    public function __construct(
        private readonly TwoFactorCodeDelivery $delivery,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $requiresTwoFactor = $user->two_factor_enabled
            || $user->two_factor_required
            || $user->two_factor_confirmed_at;

        if (! $requiresTwoFactor) {
            return $next($request);
        }

        if ($request->session()->get('two_factor_user_id') === $user->id) {
            if ($user->two_factor_required && ! $user->two_factor_confirmed_at) {
                return redirect()->route('totp.setup');
            }

            return $next($request);
        }

        if (
            ! $request->session()->has('two_factor_code_hash')
            || now()->timestamp > (int) $request->session()->get('two_factor_expires_at')
        ) {
            $code = (string) random_int(100000, 999999);
            $expiresAt = now()->addMinutes(10);
            $request->session()->put([
                'two_factor_code_hash' => Hash::make($code),
                'two_factor_expires_at' => $expiresAt->timestamp,
            ]);

            $totpConfigured = filled($user->two_factor_secret)
                && $user->two_factor_confirmed_at !== null;
            $sent = $this->delivery->deliver(
                $user,
                $code,
                $expiresAt,
                sendRemotely: ! $totpConfigured,
            );
            abort_unless(
                $totpConfigured || $sent,
                503,
                'Не удалось отправить код. Настройте SMTP или подключите Telegram.',
            );
        }

        $request->session()->put('two_factor_intended_url', $request->fullUrl());

        return redirect()->route('two-factor.challenge');
    }
}
