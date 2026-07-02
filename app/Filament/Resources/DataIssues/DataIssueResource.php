<?php

namespace App\Filament\Resources\DataIssues;

use App\Filament\Resources\DataIssues\Pages\EditDataIssue;
use App\Filament\Resources\DataIssues\Pages\ListDataIssues;
use App\Models\DataIssue;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DataIssueResource extends Resource
{
    protected static ?string $model = DataIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Сообщения об ошибках';

    protected static ?string $modelLabel = 'сообщение об ошибке';

    protected static ?string $pluralModelLabel = 'Сообщения об ошибках';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('subject')->label('Тема')->disabled(),
            Textarea::make('description')->label('Описание')->rows(6)->disabled(),
            Select::make('status')->label('Статус')->options([
                'open' => 'Новое',
                'in_progress' => 'Проверяется',
                'resolved' => 'Исправлено',
                'rejected' => 'Отклонено',
            ])->required(),
            Textarea::make('resolution')->label('Ответ / решение')->rows(5),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('subject')->label('Сообщение')->searchable()->limit(60),
            TextColumn::make('person.full_name')->label('Человек'),
            TextColumn::make('reporter.name')->label('Отправитель'),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('created_at')->label('Получено')->dateTime('d.m.Y H:i')->sortable(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataIssues::route('/'),
            'edit' => EditDataIssue::route('/{record}/edit'),
        ];
    }
}
