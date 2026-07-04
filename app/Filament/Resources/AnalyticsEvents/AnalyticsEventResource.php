<?php

namespace App\Filament\Resources\AnalyticsEvents;

use App\Filament\Resources\AnalyticsEvents\Pages\ListAnalyticsEvents;
use App\Models\AnalyticsEvent;
use App\Models\Plan;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AnalyticsEventResource extends Resource
{
    protected static ?string $model = AnalyticsEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'События аналитики';

    protected static ?string $modelLabel = 'событие аналитики';

    protected static ?string $pluralModelLabel = 'События аналитики';

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('Время')->dateTime('d.m.Y H:i:s')->sortable(),
                TextColumn::make('event_name')->label('Событие')->badge()->searchable()->sortable(),
                TextColumn::make('platform')->label('Платформа')->badge()->sortable(),
                TextColumn::make('utm_source')->label('UTM source')->searchable()->toggleable(),
                TextColumn::make('utm_campaign')->label('UTM campaign')->searchable()->toggleable(),
                TextColumn::make('landing_page')->label('Страница входа')->limit(45)->searchable()->toggleable(),
                TextColumn::make('tree.name')->label('Дерево')->toggleable(),
                TextColumn::make('user.id')->label('User ID')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('plan.name')->label('Тариф')->toggleable(),
                TextColumn::make('value')->label('Сумма')->money(fn (AnalyticsEvent $record): string => $record->currency ?: 'EUR')->toggleable(),
            ])
            ->filters([
                Filter::make('period')
                    ->label('Период')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $builder, $date) => $builder->whereDate('occurred_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $builder, $date) => $builder->whereDate('occurred_at', '<=', $date))),
                SelectFilter::make('event_name')
                    ->label('Событие')
                    ->options(fn (): array => AnalyticsEvent::query()->distinct()->orderBy('event_name')->pluck('event_name', 'event_name')->all()),
                SelectFilter::make('platform')
                    ->label('Платформа')
                    ->options(['web' => 'Web', 'telegram' => 'Telegram', 'vk' => 'VK', 'ok' => 'OK', 'max' => 'MAX']),
                SelectFilter::make('plan_id')->label('Тариф')->options(Plan::query()->pluck('name', 'id')->all()),
                Filter::make('campaign')
                    ->schema([
                        TextInput::make('utm_source')->label('UTM source'),
                        TextInput::make('utm_medium')->label('UTM medium'),
                        TextInput::make('utm_campaign')->label('UTM campaign'),
                        TextInput::make('landing_page')->label('Landing page'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['utm_source'] ?? null, fn (Builder $builder, string $value) => $builder->where('utm_source', $value))
                        ->when($data['utm_medium'] ?? null, fn (Builder $builder, string $value) => $builder->where('utm_medium', $value))
                        ->when($data['utm_campaign'] ?? null, fn (Builder $builder, string $value) => $builder->where('utm_campaign', $value))
                        ->when($data['landing_page'] ?? null, fn (Builder $builder, string $value) => $builder->where('landing_page', 'like', "%{$value}%"))),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
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
        return ['index' => ListAnalyticsEvents::route('/')];
    }
}
