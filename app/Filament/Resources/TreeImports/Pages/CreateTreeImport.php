<?php

namespace App\Filament\Resources\TreeImports\Pages;

use App\Filament\Resources\TreeImports\TreeImportResource;
use App\Services\TreeArchiveService;
use App\Services\TreeImportService;
use App\Support\CurrentTree;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTreeImport extends CreateRecord
{
    protected static string $resource = TreeImportResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...$data,
            'tree_id' => app(CurrentTree::class)->id(),
            'created_by_user_id' => auth()->id(),
            'status' => 'pending',
        ];
    }

    protected function afterCreate(): void
    {
        if ($this->record->replace_existing) {
            app(TreeArchiveService::class)->create(
                app(CurrentTree::class)->get(),
                auth()->user(),
                'before_import',
            );
        }
        $result = app(TreeImportService::class)->process($this->record);
        Notification::make()
            ->title($result->status === 'completed' ? 'Импорт завершён' : 'Ошибка импорта')
            ->body($result->error)
            ->send();
    }
}
