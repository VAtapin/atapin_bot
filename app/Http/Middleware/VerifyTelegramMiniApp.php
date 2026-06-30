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

        if ($initData === '' && app()->isLocal() && config('services.telegram.dev_user_id')) {
            $telegramData = [
                'id' => (int) config('services.telegram.dev_user_id'),
                'first_name' => 'Локальный',
                'last_name' => 'Пользователь',
            ];
        } else {
            try {
                $validated = $this->validator->validate(
                    $initData,
                    (string) config('services.telegram.bot_token'),
                );
                $telegramData = $validated['user'];
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }
        }

        $adminIds = config('services.telegram.admin_ids', []);
        $isAdmin = in_array((string) $telegramData['id'], $adminIds, true);

        $telegramUser = TelegramUser::query()->updateOrCreate(
            ['telegram_user_id' => $telegramData['id']],
            [
                'username' => $telegramData['username'] ?? null,
                'first_name' => $telegramData['first_name'] ?? null,
                'last_name' => $telegramData['last_name'] ?? null,
                'language_code' => $telegramData['language_code'] ?? null,
                'is_bot_admin' => $isAdmin,
                'last_seen_at' => now(),
            ],
        );

        if (! $telegramUser->isApproved() && ! $isAdmin) {
            return response()->json([
                'message' => 'Доступ ожидает подтверждения администратора семьи.',
                'status' => $telegramUser->status,
            ], 403);
        }

        $request->attributes->set('telegramUser', $telegramUser);

        return $next($request);
    }
}
