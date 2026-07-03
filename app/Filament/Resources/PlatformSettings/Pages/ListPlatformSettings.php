<?php

namespace App\Filament\Resources\PlatformSettings\Pages;

use App\Filament\Resources\PlatformSettings\PlatformSettingResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Mail;
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
                        Mail::raw(
                            'SMTP платформы «Я и дом мой» настроен и работает.',
                            fn ($message) => $message
                                ->to($data['email'])
                                ->subject('Проверка SMTP — Я и дом мой'),
                        );
                        Notification::make()->title('Тестовое письмо отправлено')->success()->send();
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
