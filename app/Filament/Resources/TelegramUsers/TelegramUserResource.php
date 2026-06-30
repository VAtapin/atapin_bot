<?php

namespace App\Filament\Resources\TelegramUsers;

use App\Filament\Resources\TelegramUsers\Pages\CreateTelegramUser;
use App\Filament\Resources\TelegramUsers\Pages\EditTelegramUser;
use App\Filament\Resources\TelegramUsers\Pages\ListTelegramUsers;
use App\Models\Person;
use App\Models\TelegramUser;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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

class TelegramUserResource extends Resource
{
    protected static ?string $model = TelegramUser::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static ?string $modelLabel = 'пользователь Telegram';

    protected static ?string $pluralModelLabel = 'Пользователи Telegram';

    protected static ?string $navigationLabel = 'Доступ пользователей';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('person_id')
                    ->label('Человек в семейном древе')
                    ->relationship('person', 'last_name')
                    ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
                    ->searchable(['first_name', 'middle_name', 'last_name'])
                    ->preload(),
                TextInput::make('telegram_user_id')
                    ->label('Telegram ID')
                    ->required()
                    ->numeric(),
                TextInput::make('username')
                    ->label('Username'),
                TextInput::make('first_name')
                    ->label('Имя в Telegram'),
                TextInput::make('last_name')
                    ->label('Фамилия в Telegram'),
                TextInput::make('language_code')
                    ->label('Язык'),
                Select::make('status')
                    ->label('Доступ')
                    ->options([
                        'pending' => 'Ожидает подтверждения',
                        'approved' => 'Разрешён',
                        'blocked' => 'Заблокирован',
                    ])
                    ->required()
                    ->default('pending'),
                Toggle::make('is_bot_admin')
                    ->label('Администратор бота')
                    ->required(),
                DateTimePicker::make('last_seen_at')
                    ->label('Последняя активность')
                    ->disabled(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('display_name')
            ->columns([
                TextColumn::make('display_name')
                    ->label('Пользователь')
                    ->searchable(['first_name', 'last_name', 'username']),
                TextColumn::make('person.full_name')
                    ->label('Человек в древе'),
                TextColumn::make('telegram_user_id')
                    ->label('Telegram ID')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('Username')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Доступ')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'approved' => 'Разрешён',
                        'blocked' => 'Заблокирован',
                        default => 'Ожидает',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'blocked' => 'danger',
                        default => 'warning',
                    }),
                IconColumn::make('is_bot_admin')
                    ->label('Админ')
                    ->boolean(),
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
            'index' => ListTelegramUsers::route('/'),
            'create' => CreateTelegramUser::route('/create'),
            'edit' => EditTelegramUser::route('/{record}/edit'),
        ];
    }
}
