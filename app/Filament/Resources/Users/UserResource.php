<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
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
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Платформа и доступ';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'аккаунт платформы';

    protected static ?string $pluralModelLabel = 'Аккаунты платформы';

    protected static ?string $navigationLabel = 'Аккаунты платформы';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Имя')
                    ->required(),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required(),
                TextInput::make('login')
                    ->label('Личный логин')
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->label('Пароль')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Toggle::make('is_active')
                    ->label('Доступ разрешён')
                    ->default(true)
                    ->required()
                    ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
                Toggle::make('is_super_admin')
                    ->label('Суперадминистратор платформы')
                    ->default(false)
                    ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
                Toggle::make('two_factor_required')
                    ->label('Требовать двухфакторную защиту')
                    ->helperText(fn (?User $record): ?string => $record?->is_super_admin
                        ? 'Для суперадминистратора требование включено всегда.'
                        : 'При следующем входе пользователь должен будет подключить приложение-аутентификатор.')
                    ->disabled(fn (?User $record): bool => (bool) $record?->is_super_admin)
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Имя')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('externalIdentities.provider')
                    ->label('Способы входа')
                    ->badge(),
                TextColumn::make('platform_scope')
                    ->label('Тип')
                    ->badge()
                    ->state(function (User $record): string {
                        if ($record->is_super_admin) {
                            return 'Суперадмин';
                        }

                        if ((int) ($record->owned_trees_count ?? 0) > 0) {
                            return 'Владелец дерева';
                        }

                        return 'Без дерева';
                    }),
                TextColumn::make('owned_trees_count')
                    ->label('Владеет деревьями')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('memberships_count')
                    ->label('Членств')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                IconColumn::make('is_super_admin')
                    ->label('Суперадмин')
                    ->boolean(),
                IconColumn::make('two_factor_confirmed_at')
                    ->label('TOTP подключён')
                    ->boolean(),
                IconColumn::make('two_factor_required')
                    ->label('2FA обязательна')
                    ->boolean(),
                TextColumn::make('privacy_accepted_at')
                    ->label('Согласие на данные')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Нет')
                    ->toggleable(),
                TextColumn::make('privacy_policy_version')
                    ->label('Версия политики')
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->withCount([
                'memberships',
                'memberships as owned_trees_count' => fn (Builder $query): Builder => $query->where('role', 'owner'),
            ]);

        return auth()->user()?->is_super_admin
            ? $query->where(function (Builder $query): void {
                $query
                    ->where('is_super_admin', true)
                    ->orWhereHas('memberships', fn (Builder $query): Builder => $query->where('role', 'owner'))
                    ->orWhereDoesntHave('memberships');
            })
            : $query->whereKey(auth()->id());
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canEdit($record): bool
    {
        return (bool) (auth()->user()?->is_super_admin || $record->id === auth()->id());
    }

    public static function canDelete($record): bool
    {
        if (! auth()->user()?->is_super_admin || $record->id === auth()->id()) {
            return false;
        }

        if ($record->is_super_admin && User::query()->where('is_super_admin', true)->count() <= 1) {
            return false;
        }

        return ! $record->memberships()->where('role', 'owner')->exists();
    }
}
