<?php

namespace App\Filament\Pages;

use App\Models\FamilyTree;
use App\Services\CustomTelegramBotService;
use App\Services\TreeDeletionService;
use App\Services\TreeStorageService;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class EditTreeProfile extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Основные настройки дерева';
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Название семьи')
                ->required()
                ->maxLength(150),
            TextInput::make('slug')
                ->label('Адрес дерева')
                ->prefix(config('app.url').'/family/')
                ->alphaDash()
                ->rules(['ascii', 'not_in:person,login,register,admin,manage,api'])
                ->unique(FamilyTree::class, 'slug', ignoreRecord: true)
                ->required()
                ->maxLength(80),
            TextInput::make('subtitle')
                ->label('Подзаголовок')
                ->maxLength(255),
            Select::make('timezone')
                ->label('Часовой пояс')
                ->options([
                    'Europe/Berlin' => 'Europe/Berlin',
                    'Europe/Moscow' => 'Europe/Moscow',
                    'UTC' => 'UTC',
                ])
                ->required(),
            ColorPicker::make('accent_color')
                ->label('Цвет оформления')
                ->helperText('Используется для кнопок, вкладок, ссылок и выделений семейного сайта.')
                ->required(),
            FileUpload::make('crest_path')
                ->label('Семейный герб')
                ->helperText('PNG, JPEG или WebP до 5 МБ. Показывается в шапке семейного сайта.')
                ->disk('public')
                ->directory(fn (): string => 'trees/'.$this->tenant->id.'/branding')
                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                ->maxSize(5120)
                ->image()
                ->imagePreviewHeight('160'),
            TextInput::make('primary_domain')
                ->label('Собственный домен')
                ->helperText('Доступно на тарифе с поддержкой собственного домена.')
                ->disabled(fn (): bool => ! $this->tenant->plan?->custom_domain),
            TextInput::make('custom_bot_token')
                ->label('Токен собственного Telegram-бота')
                ->password()
                ->revealable()
                ->helperText('Создайте бота в @BotFather. Токен хранится зашифрованно.')
                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->visible(fn (): bool => (bool) $this->tenant->plan?->custom_bot),
            TextInput::make('custom_bot_username')
                ->label('Username бота')
                ->disabled()
                ->dehydrated(false)
                ->visible(fn (): bool => (bool) $this->tenant->plan?->custom_bot),
            Textarea::make('_privacy_help')
                ->label('Приватность')
                ->default('Семейный сайт доступен только подтверждённым участникам. Управляйте ими в разделе «Участники дерева», а ссылки создавайте в разделе «Приглашения».')
                ->disabled()
                ->dehydrated(false)
                ->columnSpanFull(),
        ]);
    }

    protected function afterSave(): void
    {
        app(TreeStorageService::class)->recalculate($this->tenant->fresh('plan'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('configure_bot')
                ->label('Проверить и подключить бота')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => (bool) $this->tenant->plan?->custom_bot)
                ->action(function (): void {
                    try {
                        $bot = app(CustomTelegramBotService::class)->configure($this->tenant->fresh('plan'));
                        Notification::make()
                            ->title('Бот @'.($bot['username'] ?? 'подключён'))
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Не удалось подключить бота')
                            ->body($exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            Action::make('delete_tree')
                ->label('Удалить дерево')
                ->color('danger')
                ->icon('heroicon-o-trash')
                ->visible(fn (): bool => ! $this->tenant->isDeletionScheduled())
                ->schema([
                    TextInput::make('confirmation')
                        ->label('Введите точное название дерева')
                        ->required(),
                    Textarea::make('reason')->label('Причина удаления')->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    abort_unless(hash_equals($this->tenant->name, (string) $data['confirmation']), 422, 'Название не совпадает.');
                    app(TreeDeletionService::class)->schedule($this->tenant, auth()->user(), $data['reason'] ?? null);
                    $this->redirect(route('trees.choose'));
                }),
            Action::make('cancel_deletion')
                ->label('Отменить удаление')
                ->color('success')
                ->visible(fn (): bool => $this->tenant->isDeletionScheduled())
                ->action(fn () => app(TreeDeletionService::class)->cancel($this->tenant, auth()->user())),
        ];
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof FamilyTree
            && (bool) auth()->user()?->ownsTree($tenant);
    }
}
