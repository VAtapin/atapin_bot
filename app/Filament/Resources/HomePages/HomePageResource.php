<?php

namespace App\Filament\Resources\HomePages;

use App\Filament\Resources\HomePages\Pages\CreateHomePage;
use App\Filament\Resources\HomePages\Pages\EditHomePage;
use App\Filament\Resources\HomePages\Pages\ListHomePages;
use App\Models\HomePage;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class HomePageResource extends Resource
{
    protected static ?string $model = HomePage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static string|UnitEnum|null $navigationGroup = 'Сайт и справка';

    protected static ?string $navigationLabel = 'Главная страница';

    protected static ?string $modelLabel = 'главная страница';

    protected static ?string $pluralModelLabel = 'Главная страница';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->label('Состояние')
                ->options(['draft' => 'Черновик', 'published' => 'Опубликована'])
                ->required()
                ->default('published')
                ->helperText('Черновик не заменяет опубликованную главную страницу для посетителей.'),
            DateTimePicker::make('published_at')
                ->label('Дата публикации')
                ->seconds(false)
                ->helperText('Можно запланировать публикацию. Пустое поле означает публикацию сразу.'),
            Repeater::make('translations')
                ->label('SEO и превью для языков')
                ->relationship()
                ->schema([
                    Select::make('locale')
                        ->label('Язык')
                        ->options(self::locales())
                        ->required()
                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('meta_title')
                        ->label('SEO-заголовок')
                        ->maxLength(70)
                        ->helperText('Желательно до 60–70 символов.'),
                    Textarea::make('meta_description')
                        ->label('SEO-описание')
                        ->rows(3)
                        ->maxLength(180),
                    FileUpload::make('og_image_path')
                        ->label('Изображение для соцсетей')
                        ->image()
                        ->disk('public')
                        ->directory('homepage/og')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(5120)
                        ->helperText('Рекомендуемый размер 1200×630. Если пусто, используется общее изображение сайта.'),
                    TextInput::make('og_image_alt')
                        ->label('Описание изображения')
                        ->maxLength(180),
                ])
                ->defaultItems(0)
                ->addActionLabel('Добавить язык')
                ->itemLabel(fn (array $state): string => self::locales()[$state['locale'] ?? ''] ?? 'Перевод')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Состояние')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'published' ? 'Опубликована' : 'Черновик'),
                TextColumn::make('translations_count')->counts('translations')->label('Языков'),
                TextColumn::make('sections_count')->counts('sections')->label('Блоков'),
                TextColumn::make('updated_at')->label('Изменена')->dateTime('d.m.Y H:i'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function canCreate(): bool
    {
        return (bool) auth()->user()?->is_super_admin && ! HomePage::query()->exists();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomePages::route('/'),
            'create' => CreateHomePage::route('/create'),
            'edit' => EditHomePage::route('/{record}/edit'),
        ];
    }

    private static function locales(): array
    {
        return ['ru' => 'Русский', 'de' => 'Deutsch', 'en' => 'English', 'uk' => 'Українська'];
    }
}
