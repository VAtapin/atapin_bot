<?php

namespace App\Filament\Resources\TelegramGroups;

use App\Filament\Resources\TelegramGroups\Pages\CreateTelegramGroup;
use App\Filament\Resources\TelegramGroups\Pages\EditTelegramGroup;
use App\Filament\Resources\TelegramGroups\Pages\ListTelegramGroups;
use App\Models\TelegramGroup;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TelegramGroupResource extends Resource
{
    protected static ?string $model = TelegramGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'Telegram-группа';

    protected static ?string $pluralModelLabel = 'Telegram-группы';

    protected static ?string $navigationLabel = 'Группы';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('tree_id')
                    ->label('Семейное дерево')
                    ->relationship('tree', 'name')
                    ->default(fn (): ?int => app(CurrentTree::class)->id())
                    ->disabled(fn (): bool => ! auth()->user()?->is_super_admin)
                    ->dehydrated()
                    ->required(),
                TextInput::make('telegram_chat_id')
                    ->label('ID чата Telegram')
                    ->required()
                    ->numeric(),
                TextInput::make('title')
                    ->label('Название')
                    ->required(),
                TextInput::make('timezone')
                    ->label('Часовой пояс')
                    ->required()
                    ->default('Europe/Berlin'),
                TextInput::make('birthday_notification_hour')
                    ->label('Час уведомления о днях рождения')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(23)
                    ->default(9),
                Toggle::make('notify_birthdays')
                    ->label('Уведомлять о днях рождения')
                    ->default(true)
                    ->required(),
                Toggle::make('is_active')
                    ->label('Группа подтверждена')
                    ->helperText('Только подтверждённые группы получают семейные данные.')
                    ->required(),
                DatePicker::make('birthday_last_sent_on')
                    ->label('Последнее уведомление')
                    ->disabled(),
                DateTimePicker::make('last_seen_at')
                    ->label('Последняя активность')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('telegram_chat_id')
                    ->label('ID чата')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('title')
                    ->label('Название')
                    ->searchable(),
                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->searchable(),
                TextColumn::make('birthday_notification_hour')
                    ->label('Час')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('notify_birthdays')
                    ->label('Дни рождения')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Подтверждена')
                    ->boolean(),
                TextColumn::make('birthday_last_sent_on')
                    ->label('Отправлено')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('Последняя активность')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramGroups::route('/'),
            'create' => CreateTelegramGroup::route('/create'),
            'edit' => EditTelegramGroup::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        return auth()->user()?->is_super_admin
            ? $query
            : $query->where('tree_id', app(CurrentTree::class)->id());
    }
}
