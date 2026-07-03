<?php

namespace App\Filament\Pages;

use App\Services\AccountIntegrityService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class AccountIntegrity extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Проверка аккаунтов';

    protected static ?string $title = 'Целостность учётных записей';

    protected string $view = 'filament.pages.account-integrity';

    public array $report = [];

    public function mount(): void
    {
        $this->refreshReport();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Проверить снова')->action(fn () => $this->refreshReport()),
            Action::make('repair')
                ->label('Исправить безопасные ссылки')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $count = app(AccountIntegrityService::class)->repairMergedReferences();
                    $this->refreshReport();
                    Notification::make()->title("Исправлено ссылок: {$count}")->success()->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    private function refreshReport(): void
    {
        $this->report = app(AccountIntegrityService::class)->inspect();
    }
}
