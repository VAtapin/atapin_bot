<?php

namespace App\Filament\Resources\SuperAdministrators\Pages;

use App\Filament\Resources\SuperAdministrators\SuperAdministratorResource;
use App\Models\ChangeLog;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Hash;

class ListSuperAdministrators extends ListRecords
{
    protected static string $resource = SuperAdministratorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('promote')
                ->label('Назначить суперадминистратора')
                ->icon('heroicon-o-key')
                ->schema([
                    Select::make('user_id')
                        ->label('Пользователь')
                        ->options(fn (): array => User::query()
                            ->where('is_super_admin', false)
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [
                                $user->id => "{$user->name} — {$user->email}",
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('password')->label('Ваш пароль')->password()->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(Hash::check($data['password'], auth()->user()->password), 422, 'Неверный пароль.');
                    $user = User::query()->findOrFail($data['user_id']);
                    $user->update([
                        'is_super_admin' => true,
                        'two_factor_required' => true,
                        'super_admin_assigned_by_user_id' => auth()->id(),
                        'super_admin_assigned_at' => now(),
                    ]);
                    ChangeLog::query()->create([
                        'user_id' => auth()->id(),
                        'action' => 'super_admin_assigned',
                        'subject_type' => User::class,
                        'subject_id' => $user->id,
                    ]);
                    Notification::make()->title('Суперадминистратор назначен; 2FA включена')->success()->send();
                }),
        ];
    }
}
