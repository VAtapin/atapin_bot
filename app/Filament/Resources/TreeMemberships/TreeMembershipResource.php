<?php

namespace App\Filament\Resources\TreeMemberships;

use App\Filament\Resources\TreeMemberships\Pages\CreateTreeMembership;
use App\Filament\Resources\TreeMemberships\Pages\EditTreeMembership;
use App\Filament\Resources\TreeMemberships\Pages\ListTreeMemberships;
use App\Models\Person;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\UserCredentialService;
use App\Services\UserMergeService;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

class TreeMembershipResource extends Resource
{
    protected static ?string $model = TreeMembership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Участники дерева';

    protected static ?string $modelLabel = 'участник';

    protected static ?string $pluralModelLabel = 'Участники дерева';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->label('Пользователь')
                ->relationship('user', 'name')->searchable()->preload()->required(),
            Select::make('person_id')->label('Человек в дереве')
                ->relationship('person', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
                ->searchable(['first_name', 'last_name', 'middle_name'])
                ->helperText('Эта привязка определяет «Мою семью» и центрального человека на семейном сайте.'),
            Select::make('role')->label('Роль')
                ->options(function (): array {
                    if (auth()->user()?->is_super_admin) {
                        return TreeMembership::ROLES;
                    }

                    $role = auth()->user()?->memberships()
                        ->where('tree_id', app(CurrentTree::class)->id())
                        ->value('role');

                    return array_intersect_key(
                        TreeMembership::ROLES,
                        array_flip($role === 'owner'
                            ? ['moderator', 'member', 'guest']
                            : ['member', 'guest']),
                    );
                })
                ->required(),
            Select::make('status')->label('Доступ')->options([
                'pending' => 'Ожидает подтверждения',
                'approved' => 'Разрешён',
                'blocked' => 'Заблокирован',
            ])->required(),
            DateTimePicker::make('approved_at')->label('Подтверждён')->disabled(),
            DateTimePicker::make('last_seen_at')->label('Последняя активность')->disabled(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('Пользователь')->searchable(),
            TextColumn::make('user.email')->label('Email')->searchable(),
            TextColumn::make('person.full_name')->label('Человек в дереве'),
            TextColumn::make('person_linked_at')->label('Привязан')->dateTime('d.m.Y H:i'),
            TextColumn::make('role')->label('Роль')->badge()
                ->formatStateUsing(fn (string $state): string => TreeMembership::ROLES[$state] ?? $state),
            TextColumn::make('status')->label('Доступ')->badge(),
            TextColumn::make('last_seen_at')->label('Активность')->dateTime('d.m.Y H:i'),
        ])->recordActions([
            Action::make('link_person')
                ->label(fn (TreeMembership $record): string => $record->person_id
                    ? 'Изменить привязку'
                    : 'Привязать к человеку')
                ->tooltip(fn (TreeMembership $record): string => $record->person_id
                    ? 'Изменить привязку к человеку'
                    : 'Привязать к человеку')
                ->icon(Heroicon::OutlinedLink)
                ->iconButton()
                ->visible(fn (TreeMembership $record): bool => static::canManagePersonLink($record))
                ->schema([
                    Select::make('person_id')
                        ->label('Человек в дереве')
                        ->options(fn (TreeMembership $record): array => Person::query()
                            ->where('tree_id', $record->tree_id)
                            ->orderBy('last_name')
                            ->orderBy('first_name')
                            ->get()
                            ->mapWithKeys(fn ($person): array => [
                                $person->id => $person->full_name
                                    .($person->birth_date ? ' · '.$person->birth_date->format('d.m.Y') : ''),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->fillForm(fn (TreeMembership $record): array => ['person_id' => $record->person_id])
                ->action(function (TreeMembership $record, array $data): void {
                    $record->update(['person_id' => $data['person_id']]);
                    Notification::make()
                        ->title('Учётная запись привязана к человеку')
                        ->body('Теперь «Моя семья» и семейная ветка будут строиться от выбранной карточки.')
                        ->success()
                        ->send();
                }),
            Action::make('unlink_person')
                ->label('Снять привязку')
                ->tooltip('Снять привязку к человеку')
                ->icon(Heroicon::OutlinedLinkSlash)
                ->iconButton()
                ->color('warning')
                ->visible(fn (TreeMembership $record): bool => $record->role !== 'owner'
                    && (bool) $record->person_id
                    && static::canManagePersonLink($record))
                ->requiresConfirmation()
                ->action(function (TreeMembership $record): void {
                    $record->update(['person_id' => null]);
                    Notification::make()
                        ->title('Привязка снята')
                        ->body('Учётная запись осталась участником дерева, но больше не связана с карточкой человека.')
                        ->success()
                        ->send();
                }),
            Action::make('send_credentials')
                ->label('Прислать логин и пароль')
                ->tooltip('Прислать логин и пароль')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->iconButton()
                ->visible(fn (TreeMembership $record): bool => $record->status === 'approved'
                    && TelegramUser::query()->where('user_id', $record->user_id)->exists()
                    && static::canEdit($record))
                ->requiresConfirmation()
                ->action(function (TreeMembership $record): void {
                    app(UserCredentialService::class)->issueAndSend($record->user, $record->tree);
                    Notification::make()
                        ->title('Новый логин и пароль отправлены пользователю в Telegram')
                        ->success()
                        ->send();
                }),
            Action::make('merge_user')
                ->label(fn (TreeMembership $record): string => $record->role === 'owner'
                    ? 'Присоединить дубль к владельцу'
                    : 'Слить дубль в основной аккаунт')
                ->tooltip(fn (TreeMembership $record): string => $record->role === 'owner'
                    ? 'Выбрать дубль и присоединить его к владельцу'
                    : 'Слить этот дубль в владельца или другой основной аккаунт')
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->iconButton()
                ->color('warning')
                ->visible(fn (TreeMembership $record): bool => ! $record->user?->merged_at
                    && static::canMergeAccount($record))
                ->schema([
                    Select::make('merge_user_id')
                        ->label(fn (TreeMembership $record): string => $record->role === 'owner'
                            ? 'Дубль, который нужно присоединить'
                            : 'Основной аккаунт')
                        ->helperText(fn (TreeMembership $record): string => $record->role === 'owner'
                            ? 'Выбранный дубль будет присоединён к владельцу дерева. Владелец останется основной учётной записью.'
                            : 'Текущий участник будет присоединён к выбранному основному аккаунту. Обычно выбирайте владельца дерева.')
                        ->options(fn (TreeMembership $record): array => $record->role === 'owner'
                            ? static::mergeSourceOptions($record)
                            : static::mergeTargetOptions($record))
                        ->default(fn (TreeMembership $record): ?int => $record->role === 'owner'
                            ? null
                            : static::preferredMergeTargetUserId($record))
                        ->searchable()
                        ->preload()
                        ->required(),
                ])
                ->modalHeading(fn (TreeMembership $record): string => $record->role === 'owner'
                    ? 'Присоединить дубль к владельцу'
                    : 'Слить этот дубль в основной аккаунт')
                ->modalDescription(fn (TreeMembership $record): string => $record->role === 'owner'
                    ? 'Это объединяет только учётные записи доступа, а не карточки людей в родословной. Все способы входа выбранного дубля перейдут к владельцу дерева.'
                    : 'Это объединяет только учётные записи доступа, а не карточки людей в родословной. Все способы входа текущего дубля перейдут к выбранной основной записи.')
                ->requiresConfirmation()
                ->action(function (TreeMembership $record, array $data): void {
                    $targetUser = $record->role === 'owner'
                        ? $record->user
                        : User::query()
                            ->whereKey($data['merge_user_id'])
                            ->whereNull('merged_at')
                            ->firstOrFail();
                    $sourceUser = $record->role === 'owner'
                        ? User::query()
                            ->whereKey($data['merge_user_id'])
                            ->whereNull('merged_at')
                            ->firstOrFail()
                        : $record->user;

                    $sourceName = $sourceUser?->name ?: $sourceUser?->email ?: 'дубль';
                    $targetName = $targetUser?->name ?: $targetUser?->email ?: 'основная запись';

                    try {
                        app(UserMergeService::class)->merge($sourceUser, $targetUser, auth()->user());

                        Notification::make()
                            ->title('Дубли объединены')
                            ->body("{$sourceName} объединён с {$targetName}. Способы входа, Telegram и доступы перенесены к основной записи.")
                            ->success()
                            ->persistent()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Не удалось объединить дубли')
                            ->body(collect($exception->errors())->flatten()->join("\n"))
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Не удалось объединить дубли')
                            ->body($exception->getMessage() ?: 'Сервер не вернул текст ошибки. Подробности записаны в laravel.log.')
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
            EditAction::make()
                ->iconButton()
                ->tooltip('Изменить')
                ->visible(fn (TreeMembership $record): bool => static::canEdit($record)),
            DeleteAction::make()
                ->iconButton()
                ->tooltip('Удалить')
                ->visible(fn (TreeMembership $record): bool => static::canDelete($record)),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        $tree = app(CurrentTree::class)->get()
            ?: app(CurrentTree::class)->resolveDefault($user);

        return (bool) ($user?->is_super_admin || ($tree && $user?->canManageTree($tree)));
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();
        if ($user?->is_super_admin) {
            return true;
        }

        $actorRole = $user?->memberships()
            ->where('tree_id', $record->tree_id)
            ->value('role');

        return $record->role !== 'owner'
            && ($actorRole === 'owner' || ($actorRole === 'moderator' && $record->role !== 'moderator'));
    }

    public static function canManagePersonLink(TreeMembership $record): bool
    {
        $user = auth()->user();
        if ($user?->is_super_admin) {
            return true;
        }

        $actorRole = $user?->memberships()
            ->where('tree_id', $record->tree_id)
            ->value('role');

        return $actorRole === 'owner'
            || ($actorRole === 'moderator' && $record->role !== 'owner');
    }

    public static function canMergeAccount(TreeMembership $record): bool
    {
        $user = auth()->user();
        if (! $user || ! $record->user_id) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        $tree = $record->tree()->first();
        if (! $tree) {
            return false;
        }

        if ((int) $tree->owner_user_id === (int) $user->id) {
            return true;
        }

        if ($record->role === 'owner') {
            return false;
        }

        $actorRole = $user->memberships()
            ->where('tree_id', $record->tree_id)
            ->value('role');

        return $actorRole === 'moderator';
    }

    public static function mergeTargetOptions(TreeMembership $record): array
    {
        if ($record->role === 'owner') {
            return [];
        }

        $memberships = TreeMembership::query()
            ->with(['user', 'person'])
            ->where('tree_id', $record->tree_id)
            ->whereKeyNot($record->id)
            ->where('user_id', '!=', $record->user_id)
            ->whereHas('user', fn ($query) => $query->whereNull('merged_at'))
            ->orderByRaw("FIELD(role, 'owner', 'moderator', 'member', 'guest')")
            ->get();

        $options = [];

        $ownerMembership = TreeMembership::query()
            ->with(['user', 'person'])
            ->where('tree_id', $record->tree_id)
            ->where('role', 'owner')
            ->where('user_id', '!=', $record->user_id)
            ->whereNotNull('user_id')
            ->first();

        if ($ownerMembership) {
            $options[$ownerMembership->user_id] = static::membershipMergeLabel($ownerMembership);
        }

        foreach ($memberships as $membership) {
            $options[$membership->user_id] ??= static::membershipMergeLabel($membership);
        }

        $knownUserIds = $memberships->pluck('user_id')->push($record->user_id)->filter()->unique();
        $telegramUsers = TelegramUser::query()
            ->with('user')
            ->where('current_tree_id', $record->tree_id)
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $knownUserIds)
            ->whereHas('user', fn ($query) => $query->whereNull('merged_at'))
            ->get();

        foreach ($telegramUsers as $telegramUser) {
            $options[$telegramUser->user_id] = trim(
                ($telegramUser->user?->name ?: $telegramUser->display_name)
                .' — Telegram'
                .($telegramUser->username ? ' @'.$telegramUser->username : '')
                .' · ещё не участник дерева'
            );
        }

        return $options;
    }

    public static function preferredMergeTargetUserId(TreeMembership $record): ?int
    {
        if ($record->role === 'owner') {
            return null;
        }

        $ownerUserId = $record->tree()->value('owner_user_id');
        if (! $ownerUserId || (int) $ownerUserId === (int) $record->user_id) {
            return null;
        }

        $ownerIsAvailable = TreeMembership::query()
            ->where('tree_id', $record->tree_id)
            ->where('user_id', $ownerUserId)
            ->whereHas('user', fn ($query) => $query->whereNull('merged_at'))
            ->exists();

        return $ownerIsAvailable ? (int) $ownerUserId : null;
    }

    public static function mergeSourceOptions(TreeMembership $record): array
    {
        if ($record->role !== 'owner') {
            return [];
        }

        $memberships = TreeMembership::query()
            ->with(['user', 'person'])
            ->where('tree_id', $record->tree_id)
            ->whereKeyNot($record->id)
            ->where('user_id', '!=', $record->user_id)
            ->whereHas('user', fn ($query) => $query->whereNull('merged_at'))
            ->orderByRaw("FIELD(role, 'moderator', 'member', 'guest', 'owner')")
            ->get();

        $options = [];

        foreach ($memberships as $membership) {
            $options[$membership->user_id] = static::membershipMergeLabel($membership);
        }

        $knownUserIds = $memberships->pluck('user_id')->push($record->user_id)->filter()->unique();
        $telegramUsers = TelegramUser::query()
            ->with('user')
            ->where('current_tree_id', $record->tree_id)
            ->whereNotNull('user_id')
            ->whereNotIn('user_id', $knownUserIds)
            ->whereHas('user', fn ($query) => $query->whereNull('merged_at'))
            ->get();

        foreach ($telegramUsers as $telegramUser) {
            $options[$telegramUser->user_id] = trim(
                ($telegramUser->user?->name ?: $telegramUser->display_name)
                .' — Telegram'
                .($telegramUser->username ? ' @'.$telegramUser->username : '')
                .' · ещё не участник дерева'
            );
        }

        return $options;
    }

    private static function membershipMergeLabel(TreeMembership $membership): string
    {
        $prefix = $membership->role === 'owner' ? '⭐ ' : '';

        return $prefix.trim(
            ($membership->user?->name ?: 'Без имени')
            .' — '.($membership->person?->full_name ?: 'без привязки')
            .' · '.(TreeMembership::ROLES[$membership->role] ?? $membership->role)
        );
    }

    public static function canCreate(): bool
    {
        $tree = app(CurrentTree::class)->get();

        return (bool) ($tree && auth()->user()?->canManageTree($tree));
    }

    public static function canDelete($record): bool
    {
        return $record->role !== 'owner' && static::canEdit($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTreeMemberships::route('/'),
            'create' => CreateTreeMembership::route('/create'),
            'edit' => EditTreeMembership::route('/{record}/edit'),
        ];
    }
}
