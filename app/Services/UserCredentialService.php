<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserCredentialService
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly TelegramWebLogin $webLogin,
    ) {}

    /**
     * @return array{login: string, password: string}
     */
    public function issueAndSend(User $user, FamilyTree $tree): array
    {
        $membership = $user->memberships()
            ->where('tree_id', $tree->id)
            ->where('status', 'approved')
            ->first();

        if (! $membership) {
            throw ValidationException::withMessages([
                'user' => 'Пользователь не имеет разрешённого доступа к этому дереву.',
            ]);
        }

        $telegramUser = TelegramUser::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $telegramUser) {
            throw ValidationException::withMessages([
                'user' => 'Пользователь ещё не связал учётную запись с Telegram.',
            ]);
        }

        $login = $user->login ?: $this->uniqueLogin($user);
        $password = Str::password(14, true, true, false, false);
        $user->update([
            'login' => $login,
            'password' => $password,
        ]);
        $telegramUser->updateQuietly(['current_tree_id' => $tree->id]);

        $this->bot->sendMessage(
            $telegramUser->telegram_user_id,
            "🔐 <b>Доступ к сайту «Я и дом мой»</b>\n\n"
            .'Семейное дерево: <b>'.e($tree->name)."</b>\n"
            .'Логин: <code>'.e($login)."</code>\n"
            .'Новый пароль: <code>'.e($password)."</code>\n\n"
            .'Старый пароль больше не действует. Никому не пересылайте это сообщение.',
            [
                'protect_content' => true,
                'reply_markup' => [
                    'inline_keyboard' => [[
                        [
                            'text' => '🌐 Перейти на сайт',
                            'url' => $this->webLogin->createUrl($telegramUser),
                        ],
                    ]],
                ],
            ],
        );

        return compact('login', 'password');
    }

    private function uniqueLogin(User $user): string
    {
        $base = Str::slug($user->name, '');
        $base = $base !== '' ? mb_substr($base, 0, 40) : 'family'.$user->id;
        $candidate = $base;
        $suffix = 1;

        while (User::query()->where('login', $candidate)->whereKeyNot($user->id)->exists()) {
            $candidate = $base.(++$suffix);
        }

        return $candidate;
    }
}
