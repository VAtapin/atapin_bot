<?php

namespace App\Filament\Resources\Settings;

use App\Filament\Resources\Settings\Pages\CreateSetting;
use App\Filament\Resources\Settings\Pages\EditSetting;
use App\Filament\Resources\Settings\Pages\ListSettings;
use App\Models\Setting;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $recordTitleAttribute = 'label';

    protected static ?string $modelLabel = 'настройка';

    protected static ?string $pluralModelLabel = 'Настройки';

    protected static ?string $navigationLabel = 'Настройки';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label('Ключ')
                    ->required(),
                Textarea::make('value')
                    ->label('Значение')
                    ->columnSpanFull(),
                Select::make('type')
                    ->label('Тип')
                    ->options([
                        'string' => 'Текст',
                        'integer' => 'Число',
                        'boolean' => 'Да / нет',
                        'json' => 'JSON',
                    ])
                    ->required()
                    ->default('string'),
                TextInput::make('label')
                    ->label('Название')
                    ->required(),
                Textarea::make('description')
                    ->label('Описание')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->columns([
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->searchable(),
                TextColumn::make('label')
                    ->label('Название')
                    ->searchable(),
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
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
            'index' => ListSettings::route('/'),
            'create' => CreateSetting::route('/create'),
            'edit' => EditSetting::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $tree = app(CurrentTree::class)->get();

        return (bool) ($tree && auth()->user()?->ownsTree($tree));
    }
}
