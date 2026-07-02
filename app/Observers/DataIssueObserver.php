<?php

namespace App\Observers;

use App\Models\DataIssue;
use App\Services\FamilyNotificationService;

class DataIssueObserver
{
    public function created(DataIssue $issue): void
    {
        if (! app()->runningUnitTests()) {
            app(FamilyNotificationService::class)->issueCreated($issue->load('tree'));
        }
    }
}
