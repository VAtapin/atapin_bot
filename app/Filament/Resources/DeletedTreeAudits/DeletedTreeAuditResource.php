<?php

namespace App\Filament\Resources\DeletedTreeAudits;

use App\Filament\Resources\DeletedTreeAudits\Pages\ListDeletedTreeAudits;
use App\Models\DeletedTreeAudit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeletedTreeAuditResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Семейные деревья';

    protected static ?int $navigationSort = 20;

    protected static ?string $model = DeletedTreeAudit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBoxXMark;

    protected static ?string $navigationLabel = 'Удалённые деревья';

    protected static ?string $modelLabel = 'удалённое дерево';

    protected static ?string $pluralModelLabel = 'Удалённые деревья';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('deleted_at')->label('Удалено')->dateTime('d.m.Y H:i:s')->sortable(),
            TextColumn::make('name')->label('Дерево')->searchable(),
            TextColumn::make('slug')->label('Slug')->copyable(),
            TextColumn::make('original_tree_id')->label('Старый ID'),
            TextColumn::make('deletedBy.name')->label('Удалил'),
            TextColumn::make('reason')->label('Причина')->wrap()->limit(80),
            TextColumn::make('summary.people')->label('Людей'),
            TextColumn::make('summary.photos')->label('Фото'),
            TextColumn::make('summary.payment_total')->label('Сумма платежей'),
        ])->defaultSort('deleted_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListDeletedTreeAudits::route('/')];
    }
}
