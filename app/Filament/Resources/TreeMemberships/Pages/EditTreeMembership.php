<?php

namespace App\Filament\Resources\TreeMemberships\Pages;

use App\Filament\Resources\TreeMemberships\TreeMembershipResource;
use Filament\Resources\Pages\EditRecord;

class EditTreeMembership extends EditRecord
{
    protected static string $resource = TreeMembershipResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['status'] === 'approved' && ! $this->record->approved_at) {
            $data['approved_by_user_id'] = auth()->id();
            $data['approved_at'] = now();
        }

        return $data;
    }
}
