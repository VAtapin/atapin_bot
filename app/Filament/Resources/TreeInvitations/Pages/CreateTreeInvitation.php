<?php

namespace App\Filament\Resources\TreeInvitations\Pages;

use App\Filament\Resources\TreeInvitations\TreeInvitationResource;
use App\Support\CurrentTree;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTreeInvitation extends CreateRecord
{
    protected static string $resource = TreeInvitationResource::class;

    private string $plainToken;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->plainToken = bin2hex(random_bytes(32));

        return [
            ...$data,
            'tree_id' => app(CurrentTree::class)->id(),
            'created_by_user_id' => auth()->id(),
            'token_hash' => hash('sha256', $this->plainToken),
        ];
    }

    protected function afterCreate(): void
    {
        Notification::make()
            ->title('Приглашение создано')
            ->body(route('tree.invitation', $this->plainToken))
            ->persistent()
            ->success()
            ->send();
    }
}
