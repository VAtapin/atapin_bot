<?php

namespace App\Filament\Resources\HomePages\Pages;

use App\Filament\Resources\HomePages\HomePageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHomePages extends ListRecords
{
    protected static string $resource = HomePageResource::class;

    protected function getHeaderActions(): array
    {
        return HomePageResource::canCreate() ? [CreateAction::make()] : [];
    }
}
