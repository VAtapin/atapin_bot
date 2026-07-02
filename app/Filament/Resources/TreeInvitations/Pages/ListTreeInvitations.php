<?php

namespace App\Filament\Resources\TreeInvitations\Pages;

use App\Filament\Resources\TreeInvitations\TreeInvitationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreeInvitations extends ListRecords
{
    protected static string $resource = TreeInvitationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
