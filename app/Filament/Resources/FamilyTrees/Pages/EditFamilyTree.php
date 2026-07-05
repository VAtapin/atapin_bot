<?php

namespace App\Filament\Resources\FamilyTrees\Pages;

use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use App\Models\ChangeLog;
use App\Models\TreeMembership;
use App\Services\CustomDomainService;
use App\Services\OwnerPersonService;
use Filament\Resources\Pages\EditRecord;

class EditFamilyTree extends EditRecord
{
    protected static string $resource = FamilyTreeResource::class;

    private ?int $previousOwnerId = null;

    protected function beforeSave(): void
    {
        $this->previousOwnerId = $this->record->owner_user_id;
    }

    protected function afterSave(): void
    {
        if ($this->record->wasChanged('primary_domain')) {
            app(CustomDomainService::class)->prepare($this->record);
        }

        if ($this->record->owner_user_id === $this->previousOwnerId) {
            return;
        }

        app(OwnerPersonService::class)->ensure($this->record, $this->record->owner);
        if ($this->previousOwnerId) {
            TreeMembership::query()
                ->where('tree_id', $this->record->id)
                ->where('user_id', $this->previousOwnerId)
                ->first()
                ?->update(['role' => 'moderator']);
        }
        ChangeLog::query()->create([
            'tree_id' => $this->record->id,
            'user_id' => auth()->id(),
            'action' => 'ownership_transferred',
            'subject_type' => $this->record::class,
            'subject_id' => $this->record->id,
            'before' => ['owner_user_id' => $this->previousOwnerId],
            'after' => ['owner_user_id' => $this->record->owner_user_id],
        ]);
    }
}
