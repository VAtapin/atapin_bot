<?php

namespace App\Filament\Resources\TelegramGroups\Pages;

use App\Filament\Resources\TelegramGroups\TelegramGroupResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class ListTelegramGroups extends ListRecords
{
    protected static string $resource = TelegramGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getSubheading(): string|Htmlable|null
    {
        return new HtmlString(
            '<strong>Как подключить группу:</strong> добавьте семейного бота в Telegram-группу, '
            .'отправьте там <code>/start@имя_бота</code> и вернитесь сюда. '
            .'Группа появится автоматически. Откройте её и включите «Разрешить доступ группе».',
        );
    }
}
