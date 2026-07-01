<?php

namespace App\Services;

use App\Models\TelegramUser;
use Throwable;

class TelegramUserNotifier
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function newRequest(TelegramUser $user): void
    {
        $person = $user->person?->full_name ?? 'ещё не привязан';
        $username = $user->username ? '@'.$user->username : 'без username';
        $text = "👋 <b>Новая заявка в семейный архив</b>\n\n"
            .'Пользователь: <b>'.e($user->display_name)."</b>\n"
            .'Telegram: '.e($username)."\n"
            .'Telegram ID: <code>'.$user->telegram_user_id."</code>\n"
            .'Карточка: '.e($person)."\n\n"
            .'Выберите действие:';

        foreach ($this->adminTelegramIds() as $adminId) {
            $this->safeSend($adminId, $text, [
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            ['text' => '✅ Разрешить', 'callback_data' => 'access:approve:'.$user->id],
                            ['text' => '⛔ Заблокировать', 'callback_data' => 'access:block:'.$user->id],
                        ],
                        [
                            ['text' => '👑 Сделать админом', 'callback_data' => 'access:admin:'.$user->id],
                        ],
                    ],
                ],
            ]);
        }
    }

    public function changed(TelegramUser $user, array $changes): void
    {
        $lines = ['🔔 <b>Изменения в семейном архиве</b>'];

        if (array_key_exists('status', $changes)) {
            $lines[] = match ($user->status) {
                'approved' => '✅ Вам разрешён доступ к семейному архиву.',
                'blocked' => '⛔ Ваш доступ к семейному архиву отключён.',
                default => '⏳ Ваша заявка ожидает проверки администратора.',
            };
        }

        if (array_key_exists('is_bot_admin', $changes)) {
            $lines[] = $user->is_bot_admin
                ? '👑 Вам предоставлены права администратора бота.'
                : '👤 Права администратора бота сняты.';
        }

        if (array_key_exists('person_id', $changes)) {
            $lines[] = $user->person
                ? '🌿 Ваш аккаунт привязан к карточке: <b>'.e($user->person->full_name).'</b>.'
                : '🔗 Привязка к карточке человека удалена.';
        }

        if (count($lines) === 1) {
            return;
        }

        $keyboard = $user->status === 'approved'
            ? [
                'reply_markup' => [
                    'inline_keyboard' => [[
                        [
                            'text' => '🌳 Открыть семейный архив',
                            'web_app' => ['url' => config('services.telegram.mini_app_url')],
                        ],
                    ]],
                ],
            ]
            : [];

        $this->safeSend($user->telegram_user_id, implode("\n\n", $lines), $keyboard);
    }

    private function adminTelegramIds(): array
    {
        return collect(config('services.telegram.admin_ids', []))
            ->merge(
                TelegramUser::query()
                    ->where('is_bot_admin', true)
                    ->where('status', 'approved')
                    ->pluck('telegram_user_id'),
            )
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function safeSend(int|string $chatId, string $text, array $options = []): void
    {
        if (! config('services.telegram.bot_token')) {
            return;
        }

        try {
            $this->bot->sendMessage($chatId, $text, $options);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
