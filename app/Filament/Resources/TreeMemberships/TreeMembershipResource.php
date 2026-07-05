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
                ->icon(Heroicon::OutlinedLink)
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
                ->icon(Heroicon::OutlinedLinkSlash)
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
                ->icon(Heroicon::OutlinedPaperAirplane)
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
                ->label('Объединить дубль')
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->color('warning')
                ->visible(fn (TreeMembership $record): bool => ! $record->user?->merged_at
                    && static::canMergeAccount($record))
                ->schema([
                    Select::make('target_user_id')
                        ->label('Основной аккаунт')
                        ->helperText('Текущий участник будет присоединён к выбранному аккаунту. Можно выбрать участника дерева или Telegram-аккаунт, который ещё не привязан к этому дереву.')
                        ->options(fn (TreeMembership $record): array => static::mergeTargetOptions($record))
                        ->searchable()
                        ->required(),
                ])
                ->modalHeading('Объединить дубль внутри этого дерева')
                ->modalDescription(fn (TreeMembership $record): string => $record->role === 'owner'
                    ? 'Это объединяет только учётные записи доступа, а не карточки людей в родословной. Все способы входа и доступы дубля перейдут к выбранной основной записи. Так как текущая запись — владелец дерева, владение перейдёт к выбранной основной записи этого же человека.'
                    : 'Это объединяет только учётные записи доступа, а не карточки людей в родословной. Все способы входа и доступы дубля перейдут к выбранной основной записи.')
                ->requiresConfirmation()
                ->action(function (TreeMembership $record, array $data): void {
                    $sourceName = $record->user?->name ?: $record->user?->email ?: 'дубль';
                    $targetUser = User::query()
                        ->whereKey($data['target_user_id'])
                        ->whereNull('merged_at')
                        ->firstOrFail();
                    $targetName = $targetUser->name ?: $targetUser->email ?: 'основная запись';

                    app(UserMergeService::class)->merge($record->user, $targetUser, auth()->user());

                    Notification::make()
                        ->title('Дубли объединены')
                        ->body("{$sourceName} объединён с {$targetName}. Способы входа, Telegram и доступы перенесены к основной записи.")
                        ->success()
                        ->persistent()
                        ->send();
                }),
            EditAction::make()->visible(fn (TreeMembership $record): bool => static::canEdit($record)),
            DeleteAction::make()
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
        if ($user?->is_super_admin) {
            return true;
        }

        $actorRole = $user?->memberships()
            ->where('tree_id', $record->tree_id)
            ->value('role');

        return $actorRole === 'owner'
            || ($actorRole === 'moderator' && $record->role !== 'owner');
    }

    public static function mergeTargetOptions(TreeMembership $record): array
    {
        $memberships = TreeMembership::query()
            ->with(['user', 'person'])
            ->where('tree_id', $record->tree_id)
            ->whereKeyNot($record->id)
            ->where('user_id', '!=', $record->user_id)
            ->whereHas('user', fn ($query) => $query->whereNull('merged_at'))
            ->orderBy('role')
            ->get();

        $options = $memberships
            ->mapWithKeys(fn (TreeMembership $membership): array => [
                $membership->user_id => trim(
                    ($membership->user?->name ?: 'Без имени')
                    .' — '.($membership->person?->full_name ?: 'без привязки')
                    .' · '.(TreeMembership::ROLES[$membership->role] ?? $membership->role)
                ),
            ])
            ->all();

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
