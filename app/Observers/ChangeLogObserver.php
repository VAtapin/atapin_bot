<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Services\FamilyNotificationService;

class ChangeLogObserver
{
    public function created(ChangeLog $change): void
    {
        if (! app()->runningUnitTests() && $change->tree_id) {
            app(FamilyNotificationService::class)->treeChanged($change->load('tree'));
        }
    }
}
