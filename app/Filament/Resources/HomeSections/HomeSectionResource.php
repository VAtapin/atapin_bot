<?php

namespace App\Filament\Resources\HomeSections;

use App\Filament\Resources\HomeSections\Pages\CreateHomeSection;
use App\Filament\Resources\HomeSections\Pages\EditHomeSection;
use App\Filament\Resources\HomeSections\Pages\ListHomeSections;
use App\Models\FaqItem;
use App\Models\HomePage;
use App\Models\HomeSection;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class HomeSectionResource extends Resource
{
    protected static ?string $model = HomeSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Сайт и справка';

    protected static ?string $navigationLabel = 'Блоки главной';

    protected static ?string $modelLabel = 'блок главной';

    protected static ?string $pluralModelLabel = 'Блоки главной';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('home_page_id')->default(fn (): ?int => HomePage::query()->value('id'))->required(),
            Tabs::make('Редактирование блока главной')
                ->tabs([
                    Tab::make('Основное')
                        ->schema([
                            Select::make('type')
                                ->label('Тип блока')
                                ->options(self::types())
                                ->required()
                                ->live()
                                ->helperText('Тип определяет внешний вид блока. Тексты и элементы вынесены в соседние вкладки.'),
                            Toggle::make('is_enabled')->label('Показывать на сайте')->default(true),
                            TextInput::make('sort_order')
                                ->label('Порядок')
                                ->numeric()
                                ->default(0)
                                ->required()
                                ->helperText('Блоки также можно перетаскивать в общем списке.'),
                            Select::make('image_position')
                                ->label('Положение изображения')
                                ->options(['left' => 'Слева', 'right' => 'Справа'])
                                ->default('right')
                                ->visible(fn (callable $get): bool => in_array($get('type'), ['hero', 'rich_text'], true)),
                            FileUpload::make('image_path')
                                ->label('Изображение блока')
                                ->image()
                                ->disk('public')
                                ->directory('homepage/sections')
                                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                ->maxSize(8192)
                                ->imageEditor()
                                ->helperText('Необязательно. Используйте фотографию без текста — тексты берутся из перевода.')
                                ->columnSpanFull(),
                        ]),
                    Tab::make('Тексты')
                        ->schema([
                            Repeater::make('translations')
                                ->label('Тексты блока')
                                ->relationship()
                                ->collapsible()
                                ->collapsed()
                                ->schema([
                                    Select::make('locale')
                                        ->label('Язык')
                                        ->options(self::locales())
                                        ->required()
                                        ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                    TextInput::make('eyebrow')->label('Надзаголовок')->maxLength(140),
                                    TextInput::make('title')->label('Заголовок')->maxLength(220),
                                    Textarea::make('lead')->label('Краткое пояснение')->rows(3),
                                    RichEditor::make('content')
                                        ->label('Основной текст')
                                        ->fileAttachments(true)
                                        ->fileAttachmentsDisk('public')
                                        ->fileAttachmentsDirectory('homepage/content')
                                        ->fileAttachmentsAcceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->fileAttachmentsMaxSize(5120)
                                        ->helperText('Используется в текстовых блоках, приватности и финальном призыве.')
                                        ->columnSpanFull(),
                                    TextInput::make('image_alt')->label('Описание изображения')->maxLength(180),
                                    TextInput::make('primary_label')->label('Текст основной кнопки'),
                                    Select::make('primary_action')->label('Действие основной кнопки')->options(self::actions()),
                                    TextInput::make('primary_url')->label('URL или якорь основной кнопки'),
                                    TextInput::make('secondary_label')->label('Текст второй кнопки'),
                                    Select::make('secondary_action')->label('Действие второй кнопки')->options(self::actions()),
                                    TextInput::make('secondary_url')->label('URL или якорь второй кнопки'),
                                ])
                                ->defaultItems(0)
                                ->addActionLabel('Добавить язык')
                                ->itemLabel(fn (array $state): string => self::locales()[$state['locale'] ?? ''] ?? 'Перевод')
                                ->columnSpanFull(),
                        ]),
                    Tab::make('Элементы')
                        ->schema([
                            Repeater::make('items')
                                ->label('Карточки и элементы')
                                ->relationship()
                                ->orderColumn('sort_order')
                                ->collapsible()
                                ->collapsed()
                                ->schema([
                                    TextInput::make('icon')
                                        ->label('Иконка')
                                        ->maxLength(100)
                                        ->placeholder('Например 🌳')
                                        ->helperText('Одна стандартная emoji или короткий символ. HTML не используется.'),
                                    FileUpload::make('image_path')
                                        ->label('Изображение')
                                        ->image()
                                        ->disk('public')
                                        ->directory('homepage/items')
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                        ->maxSize(5120),
                                    Repeater::make('translations')
                                        ->label('Переводы элемента')
                                        ->relationship()
                                        ->collapsible()
                                        ->collapsed()
                                        ->schema([
                                            Select::make('locale')
                                                ->label('Язык')
                                                ->options(self::locales())
                                                ->required()
                                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                                            TextInput::make('title')->label('Заголовок')->maxLength(180),
                                            Textarea::make('text')->label('Текст')->rows(3),
                                            TextInput::make('image_alt')->label('Описание изображения')->maxLength(180),
                                            TextInput::make('button_label')->label('Текст кнопки'),
                                            Select::make('button_action')->label('Действие кнопки')->options(self::actions()),
                                            TextInput::make('button_url')->label('Свой URL или якорь'),
                                        ])
                                        ->defaultItems(0)
                                        ->addActionLabel('Добавить перевод')
                                        ->itemLabel(fn (array $state): string => self::locales()[$state['locale'] ?? ''] ?? 'Перевод')
                                        ->columnSpanFull(),
                                ])
                                ->visible(fn (callable $get): bool => in_array($get('type'), ['hero', 'features', 'how_it_works'], true))
                                ->defaultItems(0)
                                ->addActionLabel('Добавить карточку')
                                ->itemLabel(fn (array $state): string => $state['translations'][array_key_first($state['translations'] ?? [])]['title'] ?? 'Элемент')
                                ->columnSpanFull(),
                        ]),
                    Tab::make('Настройки')
                        ->schema([
                            Select::make('settings.faq_item_ids')
                                ->label('Вопросы для краткого FAQ')
                                ->options(fn (): array => FaqItem::query()
                                    ->where('is_published', true)
                                    ->orderBy('sort_order')
                                    ->pluck('question', 'id')
                                    ->all())
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->visible(fn (callable $get): bool => $get('type') === 'faq_teaser')
                                ->helperText('Если ничего не выбрано, показываются первые опубликованные вопросы.')
                                ->columnSpanFull(),
                        ]),
                ])
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->label('Тип')->badge()->formatStateUsing(fn (string $state): string => self::types()[$state] ?? $state),
                TextColumn::make('translations.title')->label('Заголовок')->limitList(1)->limit(60),
                TextColumn::make('items_count')->counts('items')->label('Элементов'),
                IconColumn::make('is_enabled')->label('Включён')->boolean(),
                TextColumn::make('sort_order')->label('Порядок')->sortable(),
                TextColumn::make('updated_at')->label('Изменён')->dateTime('d.m.Y H:i'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomeSections::route('/'),
            'create' => CreateHomeSection::route('/create'),
            'edit' => EditHomeSection::route('/{record}/edit'),
        ];
    }

    private static function locales(): array
    {
        return ['ru' => 'Русский', 'de' => 'Deutsch', 'en' => 'English', 'uk' => 'Українська'];
    }

    private static function types(): array
    {
        return [
            'hero' => 'Первый экран',
            'story' => 'Эмоциональный текст',
            'features' => 'Карточки преимуществ',
            'how_it_works' => 'Как это работает',
            'privacy' => 'Приватность',
            'pricing' => 'Тарифы',
            'faq_teaser' => 'Краткий FAQ',
            'final_cta' => 'Финальный призыв',
            'rich_text' => 'Произвольный текст',
        ];
    }

    private static function actions(): array
    {
        return [
            'register' => 'Регистрация',
            'login' => 'Вход',
            'faq' => 'FAQ',
            'about' => 'О проекте',
            'contacts' => 'Контакты',
            'anchor' => 'Якорь на странице',
            'custom' => 'Внешняя ссылка',
        ];
    }
}
