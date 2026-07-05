<?php

namespace App\Filament\Resources\TreeInvitations;

use App\Filament\Resources\TreeInvitations\Pages\CreateTreeInvitation;
use App\Filament\Resources\TreeInvitations\Pages\ListTreeInvitations;
use App\Models\TreeInvitation;
use App\Models\TreeMembership;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreeInvitationResource extends Resource
{
    protected static ?string $model = TreeInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Приглашения';

    protected static ?string $modelLabel = 'приглашение';

    protected static ?string $pluralModelLabel = 'Приглашения';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')->label('Для кого / примечание'),
            Select::make('person_id')->label('Сразу привязать к человеку')
                ->relationship('person', 'last_name')
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->full_name)
                ->searchable(['first_name', 'last_name', 'middle_name']),
            Select::make('role')->label('Роль')
                ->options(function (): array {
                    $tree = app(CurrentTree::class)->get();
                    $role = $tree ? auth()->user()?->roleInTree($tree) : null;
                    $allowed = in_array($role, ['owner', 'super_admin'], true)
                        || auth()->user()?->is_super_admin
                        ? ['moderator', 'member', 'guest']
                        : ['member', 'guest'];

                    return array_intersect_key(TreeMembership::ROLES, array_flip($allowed));
                })
                ->required()->default('guest'),
            TextInput::make('max_uses')->label('Количество использований')->numeric()->minValue(1)->default(1)->required(),
            DateTimePicker::make('expires_at')->label('Действует до')->minDate(now()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('label')->label('Приглашение')->placeholder('Без подписи'),
            TextColumn::make('person.full_name')->label('Человек'),
            TextColumn::make('role')->label('Роль')->badge(),
            TextColumn::make('creator.name')->label('Создал'),
            TextColumn::make('uses_count')->label('Использовано'),
            TextColumn::make('max_uses')->label('Лимит'),
            TextColumn::make('expires_at')->label('Действует до')->dateTime('d.m.Y H:i'),
            TextColumn::make('invitation_url')
                ->label('Ссылка')
                ->placeholder('Нужно перевыпустить')
                ->copyable()
                ->copyableState(fn (TreeInvitation $record): ?string => $record->invitation_url)
                ->copyMessage('Полная ссылка скопирована')
                ->tooltip(fn (TreeInvitation $record): ?string => $record->invitation_url)
                ->limit(30),
            TextColumn::make('revoked_at')->label('Отозвано')->dateTime('d.m.Y H:i'),
        ])->recordActions([
            Action::make('qr')
                ->label('QR-код')
                ->icon(Heroicon::OutlinedQrCode)
                ->visible(fn (TreeInvitation $record): bool => filled($record->invitation_url))
                ->url(fn (TreeInvitation $record): string => route('tree.invitation.qr', $record))
                ->openUrlInNewTab(),
            Action::make('reissue')
                ->label('Новая ссылка')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->action(function (TreeInvitation $record): void {
                    $plain = bin2hex(random_bytes(32));
                    $record->update([
                        'token_hash' => hash('sha256', $plain),
                        'token_ciphertext' => $plain,
                        'uses_count' => 0,
                        'revoked_at' => null,
                        'revoked_by_user_id' => null,
                    ]);
                    Notification::make()
                        ->title('Новая ссылка создана')
                        ->body($record->fresh()->invitation_url)
                        ->success()
                        ->persistent()
                        ->send();
                }),
            Action::make('revoke')
                ->label('Отозвать')
                ->color('danger')
                ->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn (TreeInvitation $record): bool => ! $record->revoked_at)
                ->requiresConfirmation()
                ->action(fn (TreeInvitation $record) => $record->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => auth()->id(),
                ])),
            DeleteAction::make(),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('tree_id', app(CurrentTree::class)->id());
    }

    public static function canViewAny(): bool
    {
        $tree = app(CurrentTree::class)->get()
            ?: app(CurrentTree::class)->resolveDefault(auth()->user());

        return (bool) (auth()->user()?->is_super_admin || ($tree && auth()->user()?->canManageTree($tree)));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTreeInvitations::route('/'),
            'create' => CreateTreeInvitation::route('/create'),
        ];
    }
}
