<?php

namespace App\Filament\Resources\FamilyTrees\Pages;

use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use App\Models\TreeMembership;
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
        if ($this->record->owner_user_id === $this->previousOwnerId) {
            return;
        }

        TreeMembership::query()->updateOrCreate(
            ['tree_id' => $this->record->id, 'user_id' => $this->record->owner_user_id],
            ['role' => 'owner', 'status' => 'approved', 'approved_at' => now()],
        );
        if ($this->previousOwnerId) {
            TreeMembership::query()
                ->where('tree_id', $this->record->id)
                ->where('user_id', $this->previousOwnerId)
                ->update(['role' => 'moderator']);
        }
    }
}
