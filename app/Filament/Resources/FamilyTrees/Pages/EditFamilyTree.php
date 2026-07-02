<?php

namespace App\Filament\Resources\FamilyTrees\Pages;

use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use App\Models\TreeMembership;
use App\Models\ChangeLog;
use App\Models\User;
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
            [
                'role' => 'owner',
                'status' => 'approved',
                'approved_by_user_id' => auth()->id(),
                'approved_at' => now(),
            ],
        );
        User::query()->whereKey($this->record->owner_user_id)->update([
            'two_factor_enabled' => true,
        ]);
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
