<?php

namespace App\Filament\Resources\PlatformSettings\Pages;

use App\Filament\Resources\PlatformSettings\PlatformSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlatformSettings extends ListRecords
{
    protected static string $resource = PlatformSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
