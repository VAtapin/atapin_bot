<?php

namespace App\Http\Middleware;

use App\Models\TelegramUser;
use App\Services\TelegramInitData;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramMiniApp
{
    public function __construct(private readonly TelegramInitData $validator) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $initData = (string) ($request->header('X-Telegram-Init-Data') ?: $request->query('initData', ''));
        $telegramUser = null;
        $telegramData = null;

        if ($initData !== '') {
            try {
                $validated = $this->validator->validate(
                    $initData,
                    (string) config('services.telegram.bot_token'),
                );
                $telegramData = $validated['user'];
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }
        } elseif ($request->session()->has('family_telegram_user_id')) {
            $telegramUser = TelegramUser::query()->find(
                $request->session()->get('family_telegram_user_id'),
            );
        } elseif (app()->isLocal() && config('services.telegram.dev_user_id')) {
            $telegramData = [
                'id' => (int) config('services.telegram.dev_user_id'),
                'first_name' => 'Локальный',
                'last_name' => 'Пользователь',
            ];
        } else {
            return response()->json([
                'message' => 'Войдите через Telegram, чтобы открыть семейный архив.',
                'login_url' => config('services.telegram.oidc_client_id')
                    ? route('telegram.login')
                    : null,
            ], 401);
        }

        if ($telegramData) {
            $adminIds = config('services.telegram.admin_ids', []);
            $isAdmin = in_array((string) $telegramData['id'], $adminIds, true);

            $telegramUser = TelegramUser::query()->updateOrCreate(
                ['telegram_user_id' => $telegramData['id']],
                [
                    'username' => $telegramData['username'] ?? null,
                    'first_name' => $telegramData['first_name'] ?? null,
                    'last_name' => $telegramData['last_name'] ?? null,
                    'language_code' => $telegramData['language_code'] ?? null,
                    'photo_url' => $telegramData['photo_url'] ?? null,
                    'is_bot_admin' => $isAdmin,
                    'last_seen_at' => now(),
                ],
            );
        }

        if (! $telegramUser) {
            $request->session()->forget('family_telegram_user_id');

            return response()->json([
                'message' => 'Сессия входа устарела. Войдите через Telegram ещё раз.',
                'login_url' => route('telegram.login'),
            ], 401);
        }

        if (! $telegramUser->isApproved() && ! $telegramUser->is_bot_admin) {
            return response()->json([
                'message' => 'Доступ ожидает подтверждения администратора семьи.',
                'status' => $telegramUser->status,
            ], 403);
        }

        $request->attributes->set('telegramUser', $telegramUser);

        return $next($request);
    }
}
