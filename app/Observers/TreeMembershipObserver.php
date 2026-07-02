<?php

namespace App\Observers;

use App\Models\TreeMembership;
use App\Models\TelegramUser;
use App\Services\FamilyNotificationService;

class TreeMembershipObserver
{
    public function created(TreeMembership $membership): void
    {
        $this->syncTelegram($membership);

        if (! app()->runningUnitTests() && $membership->status === 'pending') {
            app(FamilyNotificationService::class)->membershipRequested(
                $membership->load(['tree', 'user']),
            );
        }
    }

    public function updated(TreeMembership $membership): void
    {
        if ($membership->wasChanged(['status', 'role', 'person_id'])) {
            $this->syncTelegram($membership);
        }

        if (
            ! app()->runningUnitTests()
            && $membership->wasChanged(['status', 'role', 'person_id'])
        ) {
            app(FamilyNotificationService::class)->membershipChanged(
                $membership->load(['tree', 'user']),
            );
        }
    }

    private function syncTelegram(TreeMembership $membership): void
    {
        TelegramUser::query()
            ->where('user_id', $membership->user_id)
            ->where(fn ($query) => $query
                ->where('current_tree_id', $membership->tree_id)
                ->orWhereNull('current_tree_id'))
            ->get()
            ->each(fn (TelegramUser $telegramUser) => $telegramUser->updateQuietly([
                'current_tree_id' => $membership->tree_id,
                'person_id' => $membership->person_id,
                'status' => $membership->status,
            ]));
    }
}
