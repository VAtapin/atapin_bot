<?php

namespace App\Filament\Resources\FamilyTrees;

use App\Filament\Resources\FamilyTrees\Pages\CreateFamilyTree;
use App\Filament\Resources\FamilyTrees\Pages\EditFamilyTree;
use App\Filament\Resources\FamilyTrees\Pages\ListFamilyTrees;
use App\Models\FamilyTree;
use App\Support\FamilyTreeUrl;
use App\Services\CustomDomainService;
use App\Services\TreeDeletionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

class FamilyTreeResource extends Resource
{
    protected static string|\UnitEnum|null $navigationGroup = 'Семейные деревья';

    protected static ?int $navigationSort = 10;

    protected static ?string $model = FamilyTree::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Семейные деревья';

    protected static ?string $modelLabel = 'семейное дерево';

    protected static ?string $pluralModelLabel = 'Семейные деревья';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Название')->required(),
            TextInput::make('slug')
                ->label('Адрес')
                ->required()
                ->alphaDash()
                ->rules(['ascii', 'not_in:person,login,register,admin,manage,api'])
                ->unique(ignoreRecord: true),
            TextInput::make('subtitle')->label('Подзаголовок'),
            TextInput::make('seo_title')
                ->label('SEO / OpenGraph заголовок')
                ->maxLength(180)
                ->helperText('Если пусто, используется название семьи. Показывается при отправке ссылки на дерево.'),
            Textarea::make('seo_description')
                ->label('SEO / OpenGraph описание')
                ->rows(3)
                ->maxLength(500)
                ->helperText('Короткий красивый текст для предпросмотра ссылки. Личные данные в поисковики не открываются.'),
            FileUpload::make('og_image_path')
                ->label('Изображение OpenGraph')
                ->image()
                ->disk('public')
                ->directory('trees/og')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5120)
                ->imagePreviewHeight('160')
                ->helperText('Рекомендуемый размер 1200×630. Если не указано, используется семейный герб или общее изображение проекта.'),
            Select::make('owner_user_id')
                ->label('Владелец')
                ->relationship('owner', 'name')
                ->searchable()
                ->preload()
                ->visible(fn (?FamilyTree $record): bool => (bool) (
                    auth()->user()?->is_super_admin
                    || $record?->owner_user_id === auth()->id()
                )),
            Select::make('plan_id')
                ->label('Тариф')
                ->relationship('plan', 'name')
                ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin),
            Select::make('status')->label('Статус')->options([
                'active' => 'Активно',
                'suspended' => 'Приостановлено',
                'archived' => 'Архив',
                'deleting' => 'Ожидает удаления',
            ])->required(),
            Select::make('region')
                ->label('Регион оплаты и хранения')
                ->options([
                    'eu' => 'Европа / EUR',
                    'ru' => 'Россия / RUB',
                ])
                ->default('eu')
                ->required()
                ->helperText('Определяет валюту тарифов и набор способов оплаты. Для .ru и .рус обычно выбирайте Россия / RUB.'),
            TextInput::make('primary_domain')
                ->label('Собственный домен')
                ->dehydrateStateUsing(fn (?string $state): ?string => app(CustomDomainService::class)->normalise($state))
                ->unique(ignoreRecord: true),
            Select::make('timezone')->label('Часовой пояс')->options([
                'Europe/Berlin' => 'Europe/Berlin',
                'Europe/Moscow' => 'Europe/Moscow',
                'UTC' => 'UTC',
            ])->required(),
            ColorPicker::make('accent_color')->label('Цвет оформления'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Дерево')->searchable()->sortable(),
            TextColumn::make('slug')->label('Адрес')->copyable(),
            TextColumn::make('owner.name')->label('Владелец'),
            TextColumn::make('plan.name')->label('Тариф'),
            TextColumn::make('region')->label('Регион')->badge()
                ->formatStateUsing(fn (?string $state): string => $state === 'ru' ? 'Россия' : 'Европа'),
            TextColumn::make('people_count')->label('Людей')->counts('people'),
            TextColumn::make('storage_used_bytes')
                ->label('Хранилище')
                ->formatStateUsing(fn (int $state): string => number_format($state / 1048576, 1, ',', ' ').' МБ'),
            TextColumn::make('status')->label('Статус')->badge(),
            TextColumn::make('domain_status')->label('Домен')->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'active' => 'Активен',
                    'verified' => 'DNS подтверждён',
                    'pending_dns' => 'Ожидает DNS',
                    default => 'Не настроен',
                }),
        ])->recordActions([
            Action::make('select')
                ->label('Открыть управление')
                ->icon(Heroicon::OutlinedArrowRight)
                ->url(fn (FamilyTree $record): string => '/manage/'.$record->slug),
            Action::make('open')
                ->label('Открыть сайт')
                ->url(fn (FamilyTree $record): string => app(FamilyTreeUrl::class)->tree($record))
                ->openUrlInNewTab(),
            Action::make('export')
                ->label('Экспорт')
                ->url(fn (FamilyTree $record): string => route('trees.export', $record)),
            EditAction::make(),
            Action::make('schedule_deletion')
                ->label('Удалить дерево')
                ->color('danger')
                ->icon(Heroicon::OutlinedTrash)
                ->visible(fn (FamilyTree $record): bool => ! $record->isDeletionScheduled())
                ->schema([
                    TextInput::make('confirmation')
                        ->label('Введите точное название дерева')
                        ->required(),
                    Textarea::make('reason')->label('Причина')->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->action(function (FamilyTree $record, array $data): void {
                    abort_unless(hash_equals($record->name, (string) $data['confirmation']), 422, 'Название не совпадает.');
                    app(TreeDeletionService::class)->schedule($record, auth()->user(), $data['reason'] ?? null);
                    Notification::make()->title('Удаление запланировано через 30 дней')->warning()->send();
                }),
            Action::make('cancel_deletion')
                ->label('Отменить удаление')
                ->color('success')
                ->visible(fn (FamilyTree $record): bool => $record->isDeletionScheduled())
                ->action(fn (FamilyTree $record) => app(TreeDeletionService::class)->cancel($record, auth()->user())),
            Action::make('purge_now')
                ->label('Удалить навсегда сейчас')
                ->color('danger')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (): bool => (bool) auth()->user()?->is_super_admin)
                ->schema([
                    TextInput::make('confirmation')
                        ->label('Введите slug дерева')
                        ->required(),
                    TextInput::make('password')
                        ->label('Ваш пароль')
                        ->password()
                        ->required(),
                    Textarea::make('reason')->label('Причина')->maxLength(1000),
                ])
                ->requiresConfirmation()
                ->modalDescription('Дерево, люди и файлы будут удалены немедленно без возможности восстановления.')
                ->action(function (FamilyTree $record, array $data): void {
                    abort_unless(hash_equals($record->slug, (string) $data['confirmation']), 422, 'Slug не совпадает.');
                    abort_unless(Hash::check((string) $data['password'], auth()->user()->password), 422, 'Неверный пароль.');
                    app(TreeDeletionService::class)->purgeNow($record, auth()->user(), $data['reason'] ?? null);
                    Notification::make()->title('Дерево окончательно удалено')->success()->send();
                }),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user?->is_super_admin
            ? $query
            : $query->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user?->id)
                ->where('status', 'approved')
                ->whereIn('role', ['owner', 'moderator']));
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canEdit($record): bool
    {
        return (bool) (
            auth()->user()?->is_super_admin
            || $record->owner_user_id === auth()->id()
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFamilyTrees::route('/'),
            'create' => CreateFamilyTree::route('/create'),
            'edit' => EditFamilyTree::route('/{record}/edit'),
        ];
    }
}
