<?php

namespace App\Filament\Resources\PlatformSettings\Pages;

use App\Filament\Resources\PlatformSettings\PlatformSettingResource;
use App\Services\SmtpDiagnosticsService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListPlatformSettings extends ListRecords
{
    protected static string $resource = PlatformSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_mail')
                ->label('Проверить SMTP')
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    TextInput::make('email')
                        ->label('Куда отправить тестовое письмо')
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(SmtpDiagnosticsService::class)->test($data['email'], auth()->id());
                        Notification::make()
                            ->title('SMTP-сервер принял тестовое письмо')
                            ->body($result['message']."\nMessage-ID: ".$result['message_id'])
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()
                            ->title('Не удалось отправить письмо')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
