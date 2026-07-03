<?php

namespace App\Filament\Resources\SuperAdministrators;

use App\Filament\Resources\SuperAdministrators\Pages\ListSuperAdministrators;
use App\Filament\Resources\Users\UserResource;
use App\Models\ChangeLog;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class SuperAdministratorResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Суперадминистраторы';

    protected static ?string $modelLabel = 'суперадминистратор';

    protected static ?string $pluralModelLabel = 'Суперадминистраторы';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Имя')->searchable(),
                TextColumn::make('email')->label('Email')->searchable(),
                TextColumn::make('externalIdentities.provider')->label('Способы входа')->badge(),
                IconColumn::make('two_factor_enabled')->label('2FA')->boolean(),
                IconColumn::make('is_active')->label('Активен')->boolean(),
                TextColumn::make('super_admin_assigned_at')->label('Назначен')->dateTime('d.m.Y H:i'),
                TextColumn::make('updated_at')->label('Последняя активность')->since(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Открыть пользователя')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (User $record): string => UserResource::getUrl('edit', ['record' => $record])),
                Action::make('demote')
                    ->label('Снять права')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedKey)
                    ->visible(fn (User $record): bool => $record->id !== auth()->id()
                        && User::query()->where('is_super_admin', true)->where('is_active', true)->count() > 1)
                    ->schema([
                        TextInput::make('password')->label('Ваш пароль')->password()->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (User $record, array $data): void {
                        abort_unless(Hash::check($data['password'], auth()->user()->password), 422, 'Неверный пароль.');
                        $record->update([
                            'is_super_admin' => false,
                            'super_admin_assigned_by_user_id' => null,
                            'super_admin_assigned_at' => null,
                        ]);
                        ChangeLog::query()->create([
                            'user_id' => auth()->id(),
                            'action' => 'super_admin_revoked',
                            'subject_type' => User::class,
                            'subject_id' => $record->id,
                        ]);
                        Notification::make()->title('Права суперадминистратора сняты')->success()->send();
                    }),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_super_admin', true);
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
        return ['index' => ListSuperAdministrators::route('/')];
    }
}
