<?php

namespace App\Filament\Resources\TreeBackups;

use App\Filament\Resources\TreeBackups\Pages\ListTreeBackups;
use App\Models\TreeBackup;
use App\Services\TreeArchiveService;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreeBackupResource extends Resource
{
    protected static ?string $model = TreeBackup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $navigationLabel = 'Резервные копии';

    protected static ?string $modelLabel = 'резервная копия';

    protected static ?string $pluralModelLabel = 'Резервные копии';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Создана')->dateTime('d.m.Y H:i')->sortable(),
            TextColumn::make('type')->label('Тип')->badge(),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('size')->label('Размер')
                ->formatStateUsing(fn (int $state): string => number_format($state / 1048576, 1, ',', ' ').' МБ'),
            TextColumn::make('completed_at')->label('Завершена')->dateTime('d.m.Y H:i'),
        ])->recordActions([
            Action::make('restore')
                ->label('Восстановить')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Текущие данные дерева будут заменены выбранной резервной копией.')
                ->visible(fn (TreeBackup $record): bool => $record->status === 'completed')
                ->action(fn (TreeBackup $record) => app(TreeArchiveService::class)->restore($record)),
            DeleteAction::make(),
        ])->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function getPages(): array
    {
        return ['index' => ListTreeBackups::route('/')];
    }

    public static function canViewAny(): bool
    {
        return (bool) (
            auth()->user()?->is_super_admin
            || auth()->user()?->memberships()
                ->where('status', 'approved')
                ->where('role', 'owner')
                ->exists()
        );
    }
}
