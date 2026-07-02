<?php

namespace App\Filament\Resources\TreeMemberships\Pages;

use App\Filament\Resources\TreeMemberships\TreeMembershipResource;
use App\Support\CurrentTree;
use Filament\Resources\Pages\CreateRecord;

class CreateTreeMembership extends CreateRecord
{
    protected static string $resource = TreeMembershipResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'tree_id' => app(CurrentTree::class)->id(),
            'approved_by_user_id' => $data['status'] === 'approved' ? auth()->id() : null,
            'approved_at' => $data['status'] === 'approved' ? now() : null,
        ];
    }
}
