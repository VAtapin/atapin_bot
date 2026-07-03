<?php

namespace App\Filament\Resources\PlatformSettings\Pages;

use App\Filament\Resources\PlatformSettings\PlatformSettingResource;
use App\Models\PlatformSetting;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPlatformSetting extends EditRecord
{
    protected static string $resource = PlatformSettingResource::class;

    protected function afterSave(): void
    {
        if ($this->record->key !== 'smtp_preset') {
            return;
        }

        $preset = mb_strtolower(trim((string) $this->record->value));
        $values = match ($preset) {
            'gmail' => ['smtp.gmail.com', 587, 'tls'],
            'yandex' => ['smtp.yandex.ru', 465, 'ssl'],
            'mailru', 'mail.ru' => ['smtp.mail.ru', 465, 'ssl'],
            'outlook', 'microsoft365', 'microsoft 365' => ['smtp.office365.com', 587, 'tls'],
            default => null,
        };
        if (! $values) {
            return;
        }

        foreach (array_combine(['smtp_host', 'smtp_port', 'smtp_encryption'], $values) as $key => $value) {
            PlatformSetting::query()->where('key', $key)->first()?->update(['value' => $value]);
        }
        Notification::make()
            ->title('Параметры '.$preset.' заполнены')
            ->body('Осталось указать имя пользователя, пароль приложения и адрес отправителя.')
            ->success()
            ->send();
    }
}
