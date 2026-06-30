<?php

namespace App\Filament\Resources\FamilyEvents\Pages;

use App\Filament\Resources\FamilyEvents\FamilyEventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFamilyEvents extends ListRecords
{
    protected static string $resource = FamilyEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
