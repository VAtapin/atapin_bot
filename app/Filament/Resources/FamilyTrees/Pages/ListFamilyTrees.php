<?php

namespace App\Filament\Resources\FamilyTrees\Pages;

use App\Filament\Resources\FamilyTrees\FamilyTreeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFamilyTrees extends ListRecords
{
    protected static string $resource = FamilyTreeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
