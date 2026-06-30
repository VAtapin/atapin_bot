<?php

namespace App\Filament\Resources\TelegramUpdates;

use App\Filament\Resources\TelegramUpdates\Pages\CreateTelegramUpdate;
use App\Filament\Resources\TelegramUpdates\Pages\EditTelegramUpdate;
use App\Filament\Resources\TelegramUpdates\Pages\ListTelegramUpdates;
use App\Models\TelegramUpdate;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TelegramUpdateResource extends Resource
{
    protected static ?string $model = TelegramUpdate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $modelLabel = 'обновление Telegram';

    protected static ?string $pluralModelLabel = 'Журнал Telegram';

    protected static ?string $navigationLabel = 'Журнал Telegram';

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

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('telegram_update_id')
                    ->tel()
                    ->required()
                    ->numeric(),
                TextInput::make('chat_id')
                    ->numeric(),
                TextInput::make('telegram_user_id')
                    ->tel()
                    ->numeric(),
                TextInput::make('update_type'),
                Textarea::make('payload')
                    ->required()
                    ->columnSpanFull(),
                DateTimePicker::make('processed_at'),
                Textarea::make('error')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('telegram_update_id')
                    ->label('Update ID')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('chat_id')
                    ->label('ID чата')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('telegram_user_id')
                    ->label('ID пользователя')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('update_type')
                    ->label('Тип')
                    ->searchable(),
                TextColumn::make('processed_at')
                    ->label('Обработано')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramUpdates::route('/'),
            'create' => CreateTelegramUpdate::route('/create'),
            'edit' => EditTelegramUpdate::route('/{record}/edit'),
        ];
    }
}
