<?php

namespace App\Filament\Resources\PaymentMethods\Pages;

use App\Filament\Resources\PaymentMethods\PaymentMethodResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePaymentMethod extends CreateRecord
{
    protected static string $resource = PaymentMethodResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['credentials'] = $this->cleanCredentials($data['credentials'] ?? []);

        return $data;
    }

    private function cleanCredentials(array $credentials): array
    {
        return collect($credentials)
            ->reject(fn (mixed $value): bool => $value === null || $value === '')
            ->all();
    }
}
