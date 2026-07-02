<?php

namespace App\Filament\Resources\PlatformSettings;

use App\Filament\Resources\PlatformSettings\Pages\CreatePlatformSetting;
use App\Filament\Resources\PlatformSettings\Pages\EditPlatformSetting;
use App\Filament\Resources\PlatformSettings\Pages\ListPlatformSettings;
use App\Models\PlatformSetting;
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

class PlatformSettingResource extends Resource
{
    protected static ?string $model = PlatformSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $navigationLabel = 'Настройки платформы';

    protected static ?string $modelLabel = 'настройка платформы';

    protected static ?string $pluralModelLabel = 'Настройки платформы';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label('Ключ')->required()->unique(ignoreRecord: true),
            TextInput::make('label')->label('Название')->required(),
            Select::make('type')->label('Тип')->options([
                'string' => 'Текст',
                'integer' => 'Число',
                'boolean' => 'Да / нет',
                'json' => 'JSON',
            ])->required(),
            Textarea::make('value')->label('Значение')->columnSpanFull(),
            Textarea::make('description')->label('Описание')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('label')->label('Настройка')->searchable(),
            TextColumn::make('key')->label('Ключ')->copyable(),
            TextColumn::make('value')->label('Значение')->limit(80),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i'),
        ])->recordActions([EditAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformSettings::route('/'),
            'create' => CreatePlatformSetting::route('/create'),
            'edit' => EditPlatformSetting::route('/{record}/edit'),
        ];
    }
}
