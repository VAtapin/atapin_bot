<?php

namespace App\Observers;

use App\Models\TreeMembership;
use App\Services\FamilyNotificationService;

class TreeMembershipObserver
{
    public function created(TreeMembership $membership): void
    {
        if (! app()->runningUnitTests() && $membership->status === 'pending') {
            app(FamilyNotificationService::class)->membershipRequested(
                $membership->load(['tree', 'user']),
            );
        }
    }

    public function updated(TreeMembership $membership): void
    {
        if (
            ! app()->runningUnitTests()
            && $membership->wasChanged(['status', 'role', 'person_id'])
        ) {
            app(FamilyNotificationService::class)->membershipChanged(
                $membership->load(['tree', 'user']),
            );
        }
    }
}
