<?php

namespace App\Filament\Resources\ChangeLogs;

use App\Filament\Resources\ChangeLogs\Pages\ListChangeLogs;
use App\Models\ChangeLog;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ChangeLogResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Система';

    protected static ?int $navigationSort = 30;

    protected static ?string $model = ChangeLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'История изменений';

    protected static ?string $modelLabel = 'изменение';

    protected static ?string $pluralModelLabel = 'История изменений';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
            TextColumn::make('user.name')->label('Кто'),
            TextColumn::make('action')->label('Действие')->badge(),
            TextColumn::make('subject_type')->label('Объект')
                ->formatStateUsing(fn (string $state): string => class_basename($state)),
            TextColumn::make('subject_id')->label('ID'),
            TextColumn::make('ip_address')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ])->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return Filament::getCurrentPanel()?->getId() === 'admin'
            ? $query
            : $query->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListChangeLogs::route('/')];
    }
}
