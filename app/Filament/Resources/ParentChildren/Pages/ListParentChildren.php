<?php

namespace App\Filament\Resources\ParentChildren\Pages;

use App\Filament\Resources\ParentChildren\ParentChildResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListParentChildren extends ListRecords
{
    protected static string $resource = ParentChildResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
