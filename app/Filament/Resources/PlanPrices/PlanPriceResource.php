<?php

namespace App\Filament\Resources\PlanPrices;

use App\Filament\Resources\PlanPrices\Pages\CreatePlanPrice;
use App\Filament\Resources\PlanPrices\Pages\EditPlanPrice;
use App\Filament\Resources\PlanPrices\Pages\ListPlanPrices;
use App\Models\PlanPrice;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlanPriceResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Тарифы и платежи';

    protected static ?int $navigationSort = 12;

    protected static ?string $model = PlanPrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static ?string $navigationLabel = 'Цены тарифов';

    protected static ?string $modelLabel = 'цена тарифа';

    protected static ?string $pluralModelLabel = 'Цены тарифов';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('plan_id')
                ->label('Тариф')
                ->relationship('plan', 'name')
                ->required()
                ->searchable()
                ->preload(),
            Select::make('region')
                ->label('Регион')
                ->options([
                    'eu' => 'Европа',
                    'ru' => 'Россия',
                ])
                ->required(),
            Select::make('currency')
                ->label('Валюта')
                ->options([
                    'EUR' => 'EUR',
                    'RUB' => 'RUB',
                ])
                ->required(),
            TextInput::make('price_monthly')
                ->label('Цена в месяц')
                ->numeric()
                ->required()
                ->helperText('0 = бесплатный тариф. Для .ru обычно RUB, для .com/.de — EUR.'),
            TextInput::make('provider_price_reference')
                ->label('ID цены у провайдера')
                ->helperText('Stripe Price ID или другой внешний ID цены, если провайдер требует заранее созданную цену.'),
            Toggle::make('is_active')->label('Активна')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('plan.name')->label('Тариф')->searchable()->sortable(),
            TextColumn::make('region')->label('Регион')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Россия' : 'Европа'),
            TextColumn::make('price_monthly')->label('Цена')
                ->formatStateUsing(fn (mixed $state, PlanPrice $record): string => (float) $state <= 0.0
                    ? 'Бесплатно'
                    : number_format((float) $state, 2, ',', ' ').' '.$record->currency),
            TextColumn::make('provider_price_reference')->label('ID провайдера')->limit(28)->copyable(),
            IconColumn::make('is_active')->label('Активна')->boolean(),
        ])->recordActions([EditAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlanPrices::route('/'),
            'create' => CreatePlanPrice::route('/create'),
            'edit' => EditPlanPrice::route('/{record}/edit'),
        ];
    }
}
