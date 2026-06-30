<?php

namespace App\Filament\Resources\TelegramGroups\Pages;

use App\Filament\Resources\TelegramGroups\TelegramGroupResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramGroup extends EditRecord
{
    protected static string $resource = TelegramGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
