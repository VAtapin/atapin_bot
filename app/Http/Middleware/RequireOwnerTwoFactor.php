<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class RequireOwnerTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (
            ! $user
            || ! $user->two_factor_enabled
            || $request->session()->get('two_factor_user_id') === $user->id
        ) {
            return $next($request);
        }

        if (
            ! $request->session()->has('two_factor_code_hash')
            || now()->timestamp > (int) $request->session()->get('two_factor_expires_at')
        ) {
            $code = (string) random_int(100000, 999999);
            $request->session()->put([
                'two_factor_code_hash' => Hash::make($code),
                'two_factor_expires_at' => now()->addMinutes(10)->timestamp,
            ]);
            Mail::raw(
                "Код входа в «Я и дом мой»: {$code}\n\nКод действует 10 минут.",
                fn ($message) => $message->to($user->email)->subject('Код подтверждения входа'),
            );
        }

        return redirect()->route('two-factor.challenge');
    }
}
