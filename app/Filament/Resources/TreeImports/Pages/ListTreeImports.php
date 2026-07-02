<?php

namespace App\Filament\Resources\TreeImports\Pages;

use App\Filament\Resources\TreeImports\TreeImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreeImports extends ListRecords
{
    protected static string $resource = TreeImportResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новый импорт')];
    }
}
