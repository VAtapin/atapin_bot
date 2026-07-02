<?php

namespace App\Filament\Resources\People;

use App\Filament\Resources\People\Pages\CreatePerson;
use App\Filament\Resources\People\Pages\EditPerson;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Filament\Resources\People\RelationManagers\AlbumsRelationManager;
use App\Filament\Resources\People\RelationManagers\ChildLinksRelationManager;
use App\Filament\Resources\People\RelationManagers\ParentLinksRelationManager;
use App\Filament\Resources\People\RelationManagers\PartnershipsRelationManager;
use App\Filament\Resources\People\RelationManagers\PhotosRelationManager;
use App\Models\Person;
use App\Services\PersonMergeService;
use App\Support\CurrentTree;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static ?string $modelLabel = 'человек';

    protected static ?string $pluralModelLabel = 'Люди';

    protected static ?string $navigationLabel = 'Люди';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('Имя')
                    ->required(),
                TextInput::make('middle_name')
                    ->label('Отчество'),
                TextInput::make('last_name')
                    ->label('Фамилия')
                    ->required(),
                TextInput::make('maiden_name')
                    ->label('Девичья фамилия'),
                TextInput::make('married_name')
                    ->label('Фамилия в браке'),
                Select::make('gender')
                    ->label('Пол')
                    ->options([
                        'male' => 'Мужской',
                        'female' => 'Женский',
                        'unknown' => 'Не указан',
                    ])
                    ->required()
                    ->default('unknown'),
                DatePicker::make('birth_date')
                    ->label('Дата рождения')
                    ->native(false),
                DatePicker::make('death_date')
                    ->label('Дата смерти')
                    ->native(false)
                    ->afterOrEqual('birth_date'),
                TextInput::make('death_place')
                    ->label('Место смерти'),
                TextInput::make('burial_place')
                    ->label('Место захоронения'),
                TextInput::make('birth_place')
                    ->label('Место рождения'),
                TextInput::make('current_city')
                    ->label('Город проживания'),
                Textarea::make('current_address')
                    ->label('Адрес проживания')
                    ->rows(2),
                TextInput::make('occupation')
                    ->label('Род занятий'),
                Textarea::make('bio')
                    ->label('Биография')
                    ->rows(6)
                    ->columnSpanFull(),
                FileUpload::make('photo_path')
                    ->label('Основная фотография (быстрая загрузка)')
                    ->image()
                    ->imageEditor()
                    ->directory(fn (): string => 'trees/'.app(CurrentTree::class)->id().'/people/photos')
                    ->disk('public')
                    ->visibility('public'),
                Toggle::make('is_published')
                    ->label('Показывать в семейном древе')
                    ->default(true)
                    ->required(),
                TextInput::make('sort_order')
                    ->label('Порядок сортировки')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('gedcom_id')
                    ->label('ID в GEDCOM')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('login')
                    ->label('Логин для сайта')
                    ->unique(ignoreRecord: true)
                    ->autocomplete(false),
                TextInput::make('password')
                    ->label('Новый пароль для сайта')
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                Toggle::make('web_login_enabled')
                    ->label('Разрешить вход по логину и паролю')
                    ->default(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                ImageColumn::make('photo_url')
                    ->label('Фото')
                    ->circular(),
                TextColumn::make('full_name')
                    ->label('ФИО')
                    ->searchable(['first_name', 'last_name', 'middle_name', 'maiden_name'])
                    ->sortable(['last_name', 'first_name']),
                TextColumn::make('gender')
                    ->label('Пол')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'male' => 'Мужской',
                        'female' => 'Женский',
                        default => 'Не указан',
                    }),
                TextColumn::make('birth_date')
                    ->label('Дата рождения')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('death_date')
                    ->label('Дата смерти')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('current_city')
                    ->label('Город')
                    ->searchable(),
                TextColumn::make('occupation')
                    ->label('Род занятий')
                    ->searchable(),
                IconColumn::make('is_published')
                    ->label('В древе')
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('gender')
                    ->label('Пол')
                    ->options([
                        'male' => 'Мужской',
                        'female' => 'Женский',
                        'unknown' => 'Не указан',
                    ]),
                TernaryFilter::make('is_published')
                    ->label('Показывается в древе'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('merge')
                    ->label('Объединить дубль')
                    ->icon(Heroicon::OutlinedArrowsPointingIn)
                    ->color('warning')
                    ->schema([
                        Select::make('target_id')
                            ->label('Оставить основную карточку')
                            ->options(fn (Person $record): array => Person::query()
                                ->whereKeyNot($record->id)
                                ->orderBy('last_name')
                                ->get()
                                ->mapWithKeys(fn (Person $person): array => [$person->id => $person->full_name])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('Связи, фотографии и сведения будут перенесены в выбранную карточку. Текущая карточка попадёт в корзину.')
                    ->action(fn (Person $record, array $data) => app(PersonMergeService::class)
                        ->merge($record, Person::query()->findOrFail($data['target_id']))),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ParentLinksRelationManager::class,
            PartnershipsRelationManager::class,
            ChildLinksRelationManager::class,
            AlbumsRelationManager::class,
            PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
            'create' => CreatePerson::route('/create'),
            'edit' => EditPerson::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
