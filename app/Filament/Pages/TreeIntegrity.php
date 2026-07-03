<?php

namespace App\Filament\Pages;

use App\Models\FamilyTree;
use App\Services\TreeIntegrityService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class TreeIntegrity extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = 'Проверка данных';

    protected static ?string $title = 'Целостность семейного дерева';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.tree-integrity';

    public array $report = [];

    public function mount(): void
    {
        $this->refreshReport();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Проверить снова')
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => $this->refreshReport()),
            Action::make('deduplicate')
                ->label('Удалить точные дубли связей')
                ->color('warning')
                ->requiresConfirmation()
                ->action(function (): void {
                    $count = app(TreeIntegrityService::class)->removeExactDuplicates($this->tree());
                    $this->refreshReport();
                    Notification::make()
                        ->title("Удалено точных дублей: {$count}")
                        ->success()
                        ->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        $tree = Filament::getTenant();

        return $tree instanceof FamilyTree
            && (bool) auth()->user()?->canManageTree($tree);
    }

    private function refreshReport(): void
    {
        $this->report = app(TreeIntegrityService::class)->inspect($this->tree());
    }

    private function tree(): FamilyTree
    {
        return Filament::getTenant();
    }
}
