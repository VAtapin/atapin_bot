<?php

namespace App\Http\Middleware;

use App\Models\ExternalIdentity;
use App\Services\TelegramBot;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
            $sent = false;
            if (config('mail.default') !== 'log') {
                try {
                    Mail::raw(
                        "Код входа в «Я и дом мой»: {$code}\n\nКод действует 10 минут.",
                        fn ($message) => $message->to($user->email)->subject('Код подтверждения входа'),
                    );
                    $sent = true;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            if (! $sent) {
                $telegramId = ExternalIdentity::query()
                    ->where('user_id', $user->id)
                    ->where('provider', 'telegram')
                    ->value('provider_user_id');
                if ($telegramId) {
                    app(TelegramBot::class)->sendMessage(
                        $telegramId,
                        "🔐 Код входа в «Я и дом мой»: <b>{$code}</b>\n\nКод действует 10 минут.",
                    );
                    $sent = true;
                }
            }
            abort_unless($sent, 503, 'Не удалось отправить код. Настройте SMTP или подключите Telegram.');
        }

        $request->session()->put('two_factor_intended_url', $request->fullUrl());

        return redirect()->route('two-factor.challenge');
    }
}
