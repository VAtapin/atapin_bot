<?php

namespace App\Filament\Resources\Subscriptions;

use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\Subscription;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionResource extends Resource
{
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
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('amount')->label('Сумма'),
            TextColumn::make('ends_at')->label('До')->dateTime('d.m.Y'),
        ])->recordActions([
            EditAction::make()->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
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
}
