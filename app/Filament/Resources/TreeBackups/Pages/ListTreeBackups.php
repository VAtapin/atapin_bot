<?php

namespace App\Filament\Resources\TreeBackups\Pages;

use App\Filament\Resources\TreeBackups\TreeBackupResource;
use App\Services\TreeArchiveService;
use App\Services\TreeStorageService;
use App\Support\CurrentTree;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTreeBackups extends ListRecords
{
    protected static string $resource = TreeBackupResource::class;

    public function getSubheading(): ?string
    {
        $tree = app(CurrentTree::class)->get()?->fresh('plan');

        if (! $tree) {
            return null;
        }

        $autoEnabled = (bool) data_get($tree->settings, 'backups.auto_enabled', true);
        $frequencyDays = max(1, (int) data_get($tree->settings, 'backups.frequency_days', 1));
        $maxCopies = (int) data_get($tree->settings, 'backups.max_copies', 7);
        $backupCount = $tree->backups()->where('status', 'completed')->count();
        $backupBytes = app(TreeStorageService::class)->backupBytes($tree);
        $limit = (int) ($tree->plan?->storage_limit_bytes ?? 0);

        return 'Автобэкап: '.($autoEnabled ? "раз в {$frequencyDays} дн." : 'выключен')
            .'. Копий: '.$backupCount.' из '.($maxCopies > 0 ? $maxCopies : 'без ограничения')
            .'. Бэкапы занимают: '.$this->formatBytes($backupBytes)
            .'. Всего занято: '.$this->formatBytes((int) $tree->storage_used_bytes)
            .($limit > 0 ? ' из '.$this->formatBytes($limit) : '')
            .'. Если место заканчивается — удалите старые копии, уменьшите их количество или делайте автобэкап реже.';
    }

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

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2, ',', ' ').' ГБ';
        }

        return number_format($bytes / 1048576, 1, ',', ' ').' МБ';
    }
}
