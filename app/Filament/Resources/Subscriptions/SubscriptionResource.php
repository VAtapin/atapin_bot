<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Тарифы и платежи';

    protected static ?int $navigationSort = 20;

    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Подписки';

    protected static ?string $modelLabel = 'подписка';

    protected static ?string $pluralModelLabel = 'Подписки';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tree_id')->label('Дерево')->relationship('tree', 'name')->required(),
            Select::make('plan_id')->label('Тариф')->relationship('plan', 'name')->required(),
            Select::make('status')->label('Статус')->options([
                'trial' => 'Пробный период',
                'active' => 'Активна',
                'past_due' => 'Ожидает оплаты',
                'grace' => 'Льготный период',
                'cancelled' => 'Отменена',
                'expired' => 'Истекла',
            ])->required(),
            TextInput::make('provider')->label('Платёжная система'),
            TextInput::make('provider_reference')->label('ID платежа'),
            TextInput::make('amount')->label('Сумма')->numeric(),
            TextInput::make('currency')->label('Валюта'),
            DateTimePicker::make('starts_at')->label('Начало'),
            DateTimePicker::make('ends_at')->label('Окончание'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('tree.name')->label('Дерево'),
            TextColumn::make('plan.name')->label('Тариф'),
            TextColumn::make('plan.people_limit')
                ->label('Лимит людей')
                ->numeric(),
            TextColumn::make('plan.storage_limit_bytes')
                ->label('Хранилище')
                ->formatStateUsing(fn (?int $state): string => $state && $state >= 1073741824
                    ? number_format($state / 1073741824, 1, ',', ' ').' ГБ'
                    : number_format(((int) $state) / 1048576, 0, ',', ' ').' МБ'),
            TextColumn::make('status')->label('Статус')->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'trial' => 'Пробный период',
                    'active' => 'Активна',
                    'past_due' => 'Ожидает оплаты',
                    'grace' => 'Льготный период',
                    'cancelled' => 'Отменена',
                    'expired' => 'Истекла',
                    default => $state,
                }),
            TextColumn::make('next_billing_at')->label('Следующая оплата')->dateTime('d.m.Y'),
            TextColumn::make('amount')->label('Сумма')
                ->formatStateUsing(fn (mixed $state, Subscription $record): string => (float) $record->amount <= 0.0
                    ? 'Бесплатно'
                    : number_format((float) $record->amount, 2, ',', ' ').' '.$record->currency),
            TextColumn::make('ends_at')->label('До')->dateTime('d.m.Y'),
        ])->recordActions([
            EditAction::make()->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
            Action::make('cancel')
                ->label('Отменить в конце периода')
                ->color('warning')
                ->visible(fn (Subscription $record): bool => in_array($record->status, ['trial', 'active'], true)
                    && (auth()->user()?->is_super_admin || auth()->user()?->ownsTree($record->tree)))
                ->requiresConfirmation()
                ->action(function (Subscription $record): void {
                    if ($record->provider === 'stripe') {
                        app(BillingService::class)->cancelAtPeriodEnd($record->tree);
                    } else {
                        $record->update(['cancel_at_period_end' => true]);
                    }
                    Notification::make()->title('Автопродление отключено')->success()->send();
                }),
            Action::make('archive')
                ->label('Архивировать')
                ->visible(fn (Subscription $record): bool => (bool) auth()->user()?->is_super_admin
                    && in_array($record->status, ['cancelled', 'expired'], true)
                    && ! $record->archived_at)
                ->action(fn (Subscription $record) => $record->update(['archived_at' => now()])),
            Action::make('pay')
                ->label(fn (Subscription $record): string => $record->status === 'past_due'
                    ? 'Оплатить задолженность'
                    : 'Повторить оплату')
                ->icon(Heroicon::OutlinedCreditCard)
                ->visible(fn (Subscription $record): bool => ! auth()->user()?->is_super_admin
                    && PlatformSetting::value('billing_enabled', false)
                    && $record->plan?->isPaid()
                    && ! in_array($record->status, ['active', 'trial'], true))
                ->url(fn (Subscription $record): string => route('billing.checkout', [
                    'tree' => $record->tree,
                    'plan' => $record->plan,
                ])),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return auth()->user()?->is_super_admin
            ? $query
            : $query->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptions::route('/'),
            'edit' => EditSubscription::route('/{record}/edit'),
        ];
    }

    public static function canEdit($record): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $tree = app(CurrentTree::class)->get();

        return (bool) ($user?->is_super_admin || ($tree && $user?->ownsTree($tree)));
    }
}
