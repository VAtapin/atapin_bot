<?php

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\EditRecord;

class EditPaymentMethod extends EditRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $existingCredentials = $this->record->credentials ?? [];
        $incomingCredentials = $data['credentials'] ?? [];

        foreach ($existingCredentials as $key => $value) {
            if (($incomingCredentials[$key] ?? null) === null || $incomingCredentials[$key] === '') {
                $incomingCredentials[$key] = $value;
            }
        }

        $data['credentials'] = collect($incomingCredentials)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();

        if (blank($data['webhook_secret'] ?? null)) {
            unset($data['webhook_secret']);
        }

        return $data;
    }
}
