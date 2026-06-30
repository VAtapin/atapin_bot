<?php

namespace App\Filament\Resources\FamilyEvents\Pages;

use App\Filament\Resources\FamilyEvents\FamilyEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFamilyEvent extends EditRecord
{
    protected static string $resource = FamilyEventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
