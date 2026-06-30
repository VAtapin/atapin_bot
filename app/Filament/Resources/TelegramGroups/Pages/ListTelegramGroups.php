<?php

namespace App\Filament\Resources\TelegramGroups\Pages;

use App\Filament\Resources\TelegramGroups\TelegramGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramGroups extends ListRecords
{
    protected static string $resource = TelegramGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
