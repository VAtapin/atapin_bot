<?php

namespace App\Observers;

use App\Models\TelegramUser;
use App\Services\TelegramUserNotifier;

class TelegramUserObserver
{
    public function created(TelegramUser $user): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        if (! $user->is_bot_admin && $user->status !== 'approved') {
            app(TelegramUserNotifier::class)->newRequest($user);
        }
    }

    public function updated(TelegramUser $user): void
    {
        $watched = ['status', 'is_bot_admin', 'person_id'];
        $changes = array_intersect_key($user->getChanges(), array_flip($watched));

        if ($changes === [] || app()->runningUnitTests()) {
            return;
        }

        app(TelegramUserNotifier::class)->changed($user->load('person'), $changes);
    }
}
