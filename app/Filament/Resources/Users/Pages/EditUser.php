<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->visible(fn (): bool => UserResource::canDelete($this->record)),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (
            $this->record->is_super_admin
            && ! ($data['is_super_admin'] ?? false)
            && User::query()->where('is_super_admin', true)->count() <= 1
        ) {
            throw ValidationException::withMessages([
                'data.is_super_admin' => 'Нельзя снять права у последнего суперадминистратора.',
            ]);
        }

        if ($this->record->id === auth()->id() && ! ($data['is_active'] ?? false)) {
            throw ValidationException::withMessages([
                'data.is_active' => 'Нельзя отключить собственную учётную запись.',
            ]);
        }

        return $data;
    }
}
