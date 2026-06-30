<?php

namespace App\Filament\Resources\TelegramUpdates\Pages;

use App\Filament\Resources\TelegramUpdates\TelegramUpdateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramUpdates extends ListRecords
{
    protected static string $resource = TelegramUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
