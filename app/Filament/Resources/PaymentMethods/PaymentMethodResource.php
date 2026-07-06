<?php

namespace App\Filament\Resources\PaymentMethods;

use App\Filament\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentMethodResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Тарифы и платежи';

    protected static ?int $navigationSort = 15;

    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Способы оплаты';

    protected static ?string $modelLabel = 'способ оплаты';

    protected static ?string $pluralModelLabel = 'Способы оплаты';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required()->maxLength(255),
            TextInput::make('code')
                ->label('Код')
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('Внутренний код без пробелов: stripe_eu, yookassa_ru и т.п.'),
            Select::make('provider')
                ->label('Провайдер')
                ->options([
                    'stripe' => 'Stripe',
                    'paypal' => 'PayPal',
                    'yookassa' => 'ЮKassa',
                    'cloudpayments' => 'CloudPayments',
                    'robokassa' => 'Robokassa',
                    'manual' => 'Ручная оплата',
                ])
                ->required(),
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
            Toggle::make('is_active')
                ->label('Показывать пользователям')
                ->helperText('Если включено, владельцы деревьев этого региона смогут выбрать этот способ оплаты.'),
            Toggle::make('test_mode')->label('Тестовый режим')->default(true),
            TextInput::make('credentials.secret_key')
                ->label('Secret key / API secret')
                ->password()
                ->revealable()
                ->helperText('Stripe secret, ЮKassa secret, PayPal secret, CloudPayments API secret или другое секретное значение провайдера.'),
            TextInput::make('credentials.shop_id')
                ->label('Shop ID / Account ID')
                ->helperText('Для ЮKassa — Shop ID, для CloudPayments — Public ID, для PayPal можно оставить пустым.'),
            TextInput::make('credentials.client_id')
                ->label('Client ID')
                ->helperText('Для PayPal — Client ID.'),
            TextInput::make('credentials.webhook_id')
                ->label('Webhook ID')
                ->helperText('Для PayPal — ID webhook из Developer Dashboard. Можно оставить пустым для других провайдеров.'),
            TextInput::make('credentials.merchant_login')
                ->label('Merchant Login')
                ->helperText('Для Robokassa — MerchantLogin.'),
            TextInput::make('credentials.password1')
                ->label('Пароль #1')
                ->password()
                ->revealable()
                ->helperText('Для Robokassa: пароль для формирования ссылки оплаты.'),
            TextInput::make('credentials.password2')
                ->label('Пароль #2')
                ->password()
                ->revealable()
                ->helperText('Для Robokassa: пароль для проверки Result URL/webhook.'),
            TextInput::make('webhook_secret')
                ->label('Webhook secret')
                ->password()
                ->revealable()
                ->helperText('Для Stripe — Signing secret. Для других провайдеров обычно используется секрет из настроек выше.'),
            Textarea::make('instructions')
                ->label('Инструкция пользователю')
                ->rows(4)
                ->helperText('Показывается для ручной оплаты и как пояснение к способу оплаты.'),
            TextInput::make('sort_order')->label('Порядок')->numeric()->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Способ')->searchable()->sortable(),
            TextColumn::make('provider')->label('Провайдер')->badge()->sortable(),
            TextColumn::make('region')->label('Регион')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'ru' ? 'Россия' : 'Европа'),
            TextColumn::make('currency')->label('Валюта')->badge(),
            IconColumn::make('is_active')->label('Активен')->boolean(),
            IconColumn::make('test_mode')->label('Тест')->boolean(),
            TextColumn::make('webhook_url')
                ->label('Webhook URL')
                ->copyable()
                ->state(fn (PaymentMethod $record): string => route('payments.webhook', ['provider' => $record->provider])),
            TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i')->sortable(),
        ])->recordActions([EditAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
            'create' => CreatePaymentMethod::route('/create'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
