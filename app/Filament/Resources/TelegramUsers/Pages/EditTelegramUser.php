<?php

namespace App\Filament\Resources\TelegramUsers\Pages;

use App\Filament\Resources\TelegramUsers\TelegramUserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramUser extends EditRecord
{
    protected static string $resource = TelegramUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
