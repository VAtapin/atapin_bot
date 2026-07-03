<?php

namespace App\Filament\Pages;

use App\Models\FamilyTree;
use App\Services\CustomDomainService;
use App\Services\CustomTelegramBotService;
use App\Services\TreeDeletionService;
use App\Services\TreeStorageService;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
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
            Section::make('Основные настройки')
                ->id('basic')
                ->description('Название, адрес, часовой пояс и человек для нейтрального стартового обзора.')
                ->schema([
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
                    Select::make('start_person_id')
                        ->label('Стартовый человек дерева')
                        ->relationship('startPerson', 'last_name')
                        ->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
                        ->searchable(['first_name', 'last_name', 'middle_name'])
                        ->preload()
                        ->helperText('Используется, когда посетитель не привязан к собственной карточке.'),
                    Select::make('timezone')
                        ->label('Часовой пояс')
                        ->options([
                            'Europe/Berlin' => 'Europe/Berlin',
                            'Europe/Moscow' => 'Europe/Moscow',
                            'UTC' => 'UTC',
                        ])
                        ->required(),
                ])->columns(2),
            Section::make('Оформление и семейный герб')
                ->id('appearance')
                ->schema([
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
                ])->columns(2),
            Section::make('Собственный домен')
                ->id('domain')
                ->visible(fn (): bool => (bool) $this->tenant->plan?->custom_domain)
                ->schema([
                    TextInput::make('primary_domain')
                        ->label('Собственный домен')
                        ->helperText('Укажите только домен, например family.example.com. После сохранения подтвердите его через DNS.'),
                    Textarea::make('_domain_instructions')
                        ->label('Как подключить домен')
                        ->afterStateHydrated(fn (Textarea $component) => $component->state(
                            app(CustomDomainService::class)->instructions($this->tenant->fresh()),
                        ))
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Сначала сохраните домен. Затем выполните показанные шаги по порядку и нажмите «Проверить DNS и SSL» вверху страницы.')
                        ->rows(12)
                        ->columnSpanFull(),
                ]),
            Section::make('Мессенджеры и собственный бот')
                ->id('messengers')
                ->visible(fn (): bool => (bool) $this->tenant->plan?->custom_bot)
                ->schema([
                    TextInput::make('custom_bot_token')
                        ->label('Токен собственного Telegram-бота')
                        ->password()
                        ->revealable()
                        ->helperText('Создайте бота в @BotFather. Токен хранится зашифрованно.')
                        ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                    TextInput::make('custom_bot_username')
                        ->label('Username бота')
                        ->disabled()
                        ->dehydrated(false),
                ])->columns(2),
            Section::make('Приватность')
                ->id('privacy')
                ->schema([
                    Textarea::make('_privacy_help')
                        ->label('Доступ к семейному архиву')
                        ->default('Семейный сайт доступен только подтверждённым участникам. Управляйте ими в разделе «Участники дерева», а ссылки создавайте в разделе «Приглашения».')
                        ->disabled()
                        ->dehydrated(false)
                        ->columnSpanFull(),
                    Toggle::make('settings.allow_congratulations')
                        ->label('Разрешить поздравления между участниками')
                        ->default(true),
                    Toggle::make('settings.allow_telegram_congratulations')
                        ->label('Доставлять поздравления в Telegram')
                        ->default(true),
                ]),
        ]);
    }

    protected function afterSave(): void
    {
        app(TreeStorageService::class)->recalculate($this->tenant->fresh('plan'));
        if ($this->tenant->wasChanged('primary_domain')) {
            app(CustomDomainService::class)->prepare($this->tenant);
        }
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('primary_domain', $data)) {
            $data['primary_domain'] = app(CustomDomainService::class)->normalise($data['primary_domain']);
        }

        return $data;
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
                        report($exception);
                        Notification::make()
                            ->title('Не удалось подключить бота')
                            ->body($exception instanceof QueryException
                                ? 'Настройки бота не удалось сохранить в базе. Установите последнее обновление и повторите попытку.'
                                : $exception->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            Action::make('verify_domain')
                ->label('Проверить DNS и SSL')
                ->icon('heroicon-o-globe-alt')
                ->visible(fn (): bool => (bool) (
                    $this->tenant->plan?->custom_domain
                    && $this->tenant->primary_domain
                ))
                ->action(function (): void {
                    try {
                        $result = app(CustomDomainService::class)->verify($this->tenant->fresh());
                        $notification = Notification::make()
                            ->title($result['verified'] ? 'Домен подтверждён' : 'Домен пока не подтверждён')
                            ->body($result['error'] ?: 'DNS и HTTPS готовы. Домен активирован.')
                            ->persistent();

                        $result['verified']
                            ? $notification->success()
                            : $notification->warning();
                        $notification->send();
                        $this->fillForm();
                    } catch (Throwable $exception) {
                        report($exception);
                        Notification::make()
                            ->title('Не удалось проверить домен')
                            ->body('Проверка DNS или HTTPS временно недоступна. Повторите попытку немного позже.')
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
            Action::make('purge_now')
                ->label('Удалить навсегда сейчас')
                ->color('danger')
                ->icon('heroicon-o-no-symbol')
                ->visible(fn (): bool => (bool) (
                    auth()->user()?->is_super_admin
                    || $this->tenant->owner_user_id === auth()->id()
                ))
                ->schema([
                    TextInput::make('confirmation')
                        ->label('Введите slug дерева: '.$this->tenant->slug)
                        ->required(),
                    TextInput::make('password')->label('Ваш пароль')->password()->required(),
                    Textarea::make('reason')->label('Причина')->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->modalDescription('Все семейные данные и файлы будут удалены немедленно. Восстановление невозможно.')
                ->action(function (array $data): void {
                    abort_unless(hash_equals($this->tenant->slug, (string) $data['confirmation']), 422, 'Slug не совпадает.');
                    abort_unless(Hash::check((string) $data['password'], auth()->user()->password), 422, 'Неверный пароль.');
                    app(TreeDeletionService::class)->purgeNow($this->tenant, auth()->user(), $data['reason'] ?? null);
                    $this->redirect(auth()->user()->is_super_admin ? '/admin/family-trees' : route('trees.choose'));
                }),
        ];
    }

    public static function canView(Model $tenant): bool
    {
        return $tenant instanceof FamilyTree
            && (bool) auth()->user()?->ownsTree($tenant);
    }
}
