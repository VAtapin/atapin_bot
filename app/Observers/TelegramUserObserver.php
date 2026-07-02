<?php

namespace App\Observers;

use App\Models\TelegramUser;
use App\Services\TelegramUserNotifier;

use function Illuminate\Support\defer;

class TelegramUserObserver
{
    public function created(TelegramUser $user): void
    {
        // Заявка относится к конкретному дереву и отправляется
        // TreeMembershipObserver после создания членства.
    }

    public function updated(TelegramUser $user): void
    {
        $watched = ['status', 'is_bot_admin', 'person_id'];
        $changes = array_intersect_key($user->getChanges(), array_flip($watched));

        if ($changes === [] || app()->runningUnitTests()) {
            return;
        }

        $userId = $user->getKey();

        defer(function () use ($userId, $changes): void {
            $user = TelegramUser::query()->with('person')->find($userId);

            if ($user) {
                app(TelegramUserNotifier::class)->changed($user, $changes);
            }
        });
    }
}
