<?php

namespace App\Filament\Resources\TelegramUsers\Pages;

use App\Filament\Resources\TelegramUsers\TelegramUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramUser extends CreateRecord
{
    protected static string $resource = TelegramUserResource::class;
}
