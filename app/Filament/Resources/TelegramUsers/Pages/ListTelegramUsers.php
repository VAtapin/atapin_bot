<?php

namespace App\Filament\Resources\TelegramUsers\Pages;

use App\Filament\Resources\TelegramUsers\TelegramUserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramUsers extends ListRecords
{
    protected static string $resource = TelegramUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
