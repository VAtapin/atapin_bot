<?php

namespace App\Filament\Resources\ParentChildren\Pages;

use App\Filament\Resources\ParentChildren\ParentChildResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditParentChild extends EditRecord
{
    protected static string $resource = ParentChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
