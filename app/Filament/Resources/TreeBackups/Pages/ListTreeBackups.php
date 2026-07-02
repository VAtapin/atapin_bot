<?php

namespace App\Filament\Resources\TreeBackups\Pages;

use App\Filament\Resources\TreeBackups\TreeBackupResource;
use App\Services\TreeArchiveService;
use App\Support\CurrentTree;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTreeBackups extends ListRecords
{
    protected static string $resource = TreeBackupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backup')
                ->label('Создать резервную копию')
                ->action(function (): void {
                    $backup = app(TreeArchiveService::class)->create(
                        app(CurrentTree::class)->get(),
                        auth()->user(),
                    );
                    Notification::make()
                        ->title($backup->status === 'completed' ? 'Копия создана' : 'Ошибка резервного копирования')
                        ->body($backup->error)
                        ->status($backup->status === 'completed' ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }
}
