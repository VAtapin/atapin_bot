<?php

namespace App\Filament\Resources\FamilyTrees\Pages;

use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use App\Models\Subscription;
use App\Models\TreeMembership;
use App\Services\CustomDomainService;
use Filament\Resources\Pages\CreateRecord;

class CreateFamilyTree extends CreateRecord
{
    protected static string $resource = FamilyTreeResource::class;

    protected function afterCreate(): void
    {
        if ($this->record->primary_domain) {
            app(CustomDomainService::class)->prepare($this->record);
        }
        if ($this->record->owner_user_id) {
            TreeMembership::query()->firstOrCreate([
                'tree_id' => $this->record->id,
                'user_id' => $this->record->owner_user_id,
            ], [
                'role' => 'owner',
                'status' => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
            ]);
        }
        if ($this->record->plan_id) {
            Subscription::query()->firstOrCreate([
                'tree_id' => $this->record->id,
                'plan_id' => $this->record->plan_id,
            ], [
                'status' => 'trial',
                'amount' => 0,
                'currency' => $this->record->plan->currency,
                'starts_at' => now(),
                'ends_at' => now()->addDays(30),
            ]);
        }
    }
}
