<?php

namespace App\Filament\Resources\People\Pages;

use App\Filament\Resources\People\PersonResource;
use App\Models\TreeMembership;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('link_account')
                ->label($this->record->memberships()->exists() ? 'Изменить привязку' : 'Привязать учётную запись')
                ->icon('heroicon-o-link')
                ->schema([
                    Select::make('user_id')
                        ->label('Пользователь')
                        ->options(fn (): array => User::query()
                            ->where('is_active', true)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (User $user): array => [
                                $user->id => "{$user->name} — {$user->email}",
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('role')
                        ->label('Роль')
                        ->options([
                            'member' => 'Член семьи',
                            'guest' => 'Гость',
                            'moderator' => 'Администратор-модератор',
                        ])
                        ->default('member')
                        ->required(),
                ])
                ->fillForm(function (): array {
                    $membership = $this->record->memberships()->first();

                    return [
                        'user_id' => $membership?->user_id,
                        'role' => $membership?->role === 'owner'
                            ? 'member'
                            : ($membership?->role ?: 'member'),
                    ];
                })
                ->action(function (array $data): void {
                    DB::transaction(function () use ($data): void {
                        $treeId = $this->record->tree_id;
                        TreeMembership::query()
                            ->where('tree_id', $treeId)
                            ->where('person_id', $this->record->id)
                            ->where('user_id', '!=', $data['user_id'])
                            ->update([
                                'person_id' => null,
                                'person_linked_at' => null,
                                'person_linked_by_user_id' => null,
                            ]);
                        $membership = TreeMembership::query()->firstOrNew([
                            'tree_id' => $treeId,
                            'user_id' => $data['user_id'],
                        ]);
                        $membership->fill([
                            'person_id' => $this->record->id,
                            'role' => (int) $this->record->tree->owner_user_id === (int) $data['user_id']
                                ? 'owner'
                                : $data['role'],
                            'status' => 'approved',
                            'approved_by_user_id' => auth()->id(),
                            'approved_at' => $membership->approved_at ?: now(),
                        ])->save();
                    });
                    Notification::make()->title('Учётная запись привязана')->success()->send();
                }),
            Action::make('unlink_account')
                ->label('Снять привязку')
                ->icon('heroicon-o-link-slash')
                ->color('warning')
                ->visible(fn (): bool => $this->record->memberships()
                    ->where('role', '!=', 'owner')
                    ->exists())
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->record->memberships()
                        ->where('role', '!=', 'owner')
                        ->update([
                            'person_id' => null,
                            'person_linked_at' => null,
                            'person_linked_by_user_id' => null,
                        ]);
                    Notification::make()->title('Привязка снята')->success()->send();
                }),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
