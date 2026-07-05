<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Plan;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Тарифы и платежи';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Тарифы';

    protected static ?string $modelLabel = 'тариф';

    protected static ?string $pluralModelLabel = 'Тарифы';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required(),
            TextInput::make('code')->label('Код')->required()->unique(ignoreRecord: true),
            Textarea::make('description')->label('Описание'),
            TextInput::make('price_monthly')->label('Цена в месяц')->numeric()->required(),
            TextInput::make('currency')->label('Валюта')->default('EUR')->required(),
            TextInput::make('provider_price_reference')
                ->label('Stripe Price ID')
                ->placeholder('price_…')
                ->helperText('Создайте в Stripe ежемесячную recurring Price и вставьте её ID. Нужен для безопасной смены тарифа; при первой покупке без него цена создаётся Checkout автоматически.'),
            TextInput::make('storage_limit_mb')
                ->label('Хранилище, МБ')
                ->helperText('1024 МБ = 1 ГБ. Значение хранится внутри системы в байтах.')
                ->numeric()
                ->minValue(10)
                ->maxValue(1048576)
                ->suffix('МБ')
                ->required(),
            TextInput::make('people_limit')->label('Лимит людей')->numeric()->required(),
            TextInput::make('member_limit')->label('Лимит участников')->numeric()->required(),
            Toggle::make('custom_bot')->label('Собственный бот'),
            Toggle::make('custom_domain')->label('Собственный домен'),
            Toggle::make('is_active')->label('Доступен')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Тариф'),
            TextColumn::make('price_monthly')->label('Цена')->money(fn (Plan $record): string => $record->currency),
            TextColumn::make('people_limit')->label('Людей'),
            TextColumn::make('member_limit')->label('Участников'),
            TextColumn::make('storage_limit_bytes')->label('Хранилище')
                ->formatStateUsing(fn (int $state): string => $state >= 1073741824
                    ? number_format($state / 1073741824, 1, ',', ' ').' ГБ'
                    : number_format($state / 1048576, 0, ',', ' ').' МБ'),
            IconColumn::make('is_active')->label('Активен')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
