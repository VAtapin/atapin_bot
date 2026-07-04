<?php

namespace App\Observers;

use App\Models\TreeInvitation;
use App\Services\AnalyticsService;

class TreeInvitationAnalyticsObserver
{
    public function created(TreeInvitation $invitation): void
    {
        app(AnalyticsService::class)->record(
            'invite_sent',
            user: $invitation->creator,
            tree: $invitation->tree,
            parameters: ['tree_id' => $invitation->tree_id, 'invitation_id' => $invitation->id],
            deduplicationKey: "invite_sent:{$invitation->id}",
            queueForBrowser: true,
        );
    }
}
