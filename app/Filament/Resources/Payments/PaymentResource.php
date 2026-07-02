<?php

namespace App\Filament\Resources\Payments;

use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Платежи';

    protected static ?string $modelLabel = 'платёж';

    protected static ?string $pluralModelLabel = 'Платежи';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tree.name')->label('Дерево')->searchable(),
                TextColumn::make('plan.name')->label('Тариф'),
                TextColumn::make('provider')->label('Провайдер')->badge(),
                TextColumn::make('provider_reference')->label('Номер')->copyable(),
                TextColumn::make('status')->label('Статус')->badge(),
                TextColumn::make('amount')->label('Сумма')->money(
                    fn (Payment $record): string => $record->currency,
                ),
                TextColumn::make('paid_at')->label('Оплачен')->dateTime('d.m.Y H:i'),
                TextColumn::make('created_at')->label('Создан')->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return auth()->user()?->is_super_admin
            ? $query
            : $query->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $tree = app(CurrentTree::class)->get();

        return (bool) ($user?->is_super_admin || ($tree && $user?->ownsTree($tree)));
    }

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

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
        ];
    }
}
