<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Services\FamilyNotificationService;

class ChangeLogObserver
{
    public function created(ChangeLog $change): void
    {
        // Merely opening a tree as a platform administrator is an audit event,
        // not a family-data change. Keep it in the log without alarming owners.
        if (
            ! app()->runningUnitTests()
            && $change->tree_id
            && $change->action !== 'platform_admin_entered_tree'
        ) {
            app(FamilyNotificationService::class)->treeChanged($change->load('tree'));
        }
    }
}
