<?php

namespace App\Filament\Resources\PlatformSettings;

use App\Filament\Resources\PlatformSettings\Pages\CreatePlatformSetting;
use App\Filament\Resources\PlatformSettings\Pages\EditPlatformSetting;
use App\Filament\Resources\PlatformSettings\Pages\ListPlatformSettings;
use App\Models\PlatformSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
            TextInput::make('label')->label('Настройка')->disabled()->dehydrated(false),
            TextInput::make('key')->label('Системный ключ')->disabled()->dehydrated(false),
            Select::make('group')->label('Раздел')->options([
                'general' => 'Общие',
                'mail' => 'Почта и SMTP',
                'billing' => 'Платежи',
                'analytics' => 'Аналитика и реклама',
            ])->disabled()->dehydrated(false),
            Select::make('type')->label('Тип')->options([
                'string' => 'Текст',
                'integer' => 'Число',
                'boolean' => 'Да / нет',
                'json' => 'JSON',
            ])->disabled()->dehydrated(false),
            TextInput::make('value')
                ->label('Значение')
                ->password(fn (?PlatformSetting $record): bool => (bool) $record?->is_secret)
                ->revealable(fn (?PlatformSetting $record): bool => (bool) $record?->is_secret)
                ->afterStateHydrated(function (TextInput $component, ?PlatformSetting $record): void {
                    if ($record?->is_secret) {
                        $component->state(null);
                    }
                })
                ->dehydrated(fn (?string $state, ?PlatformSetting $record): bool => ! $record?->is_secret || filled($state))
                ->helperText(fn (?PlatformSetting $record): ?string => $record?->description)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('label')->label('Настройка')->searchable(),
            TextColumn::make('group')->label('Раздел')->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'mail' => 'Почта и SMTP',
                    'billing' => 'Платежи',
                    'analytics' => 'Аналитика и реклама',
                    default => 'Общие',
                }),
            TextColumn::make('value')->label('Значение')
                ->formatStateUsing(fn (?string $state, PlatformSetting $record): string => $record->is_secret && filled($state) ? '••••••••' : (string) $state)
                ->limit(80),
            TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i'),
        ])->filters([
            SelectFilter::make('group')->label('Раздел')->options([
                'general' => 'Общие',
                'mail' => 'Почта и SMTP',
                'billing' => 'Платежи',
                'analytics' => 'Аналитика и реклама',
            ]),
        ])->defaultSort('sort_order')->recordActions([EditAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return false;
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
