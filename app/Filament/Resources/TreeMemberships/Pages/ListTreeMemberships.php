<?php

namespace App\Filament\Resources\TreeMemberships\Pages;

use App\Filament\Resources\TreeMemberships\TreeMembershipResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreeMemberships extends ListRecords
{
    protected static string $resource = TreeMembershipResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
