<?php

namespace App\Filament\Resources\HomePages\Pages;

use App\Filament\Resources\HomePages\HomePageResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditHomePage extends EditRecord
{
    protected static string $resource = HomePageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Открыть главную')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => route('home.preview'))
                ->openUrlInNewTab(),
        ];
    }
}
