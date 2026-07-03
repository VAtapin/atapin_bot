<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\UserMergeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'пользователь';

    protected static ?string $pluralModelLabel = 'Пользователи';

    protected static ?string $navigationLabel = 'Пользователи';

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
                Action::make('merge')
                    ->label('Объединить дубль')
                    ->color('warning')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->visible(fn (User $record): bool => $record->id !== auth()->id() && ! $record->merged_at)
                    ->schema([
                        Select::make('target_id')
                            ->label('Основная учётная запись')
                            ->options(fn (User $record): array => User::query()
                                ->whereKeyNot($record->id)
                                ->whereNull('merged_at')
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} — {$user->email}"])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (User $record, array $data): void {
                        app(UserMergeService::class)->merge(
                            $record,
                            User::query()->findOrFail($data['target_id']),
                            auth()->user(),
                        );
                        Notification::make()->title('Учётные записи объединены')->success()->send();
                    }),
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
        $query = parent::getEloquentQuery();

        return auth()->user()?->is_super_admin
            ? $query
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
