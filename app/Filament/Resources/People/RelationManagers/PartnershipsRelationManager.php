<?php

namespace App\Filament\Resources\People\RelationManagers;

use App\Models\Partnership;
use App\Models\Person;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PartnershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'partnershipsAsOne';

    protected static ?string $title = 'Супруги и партнёры';

    protected static ?string $modelLabel = 'союз';

    protected static ?string $pluralModelLabel = 'союзы';

    public function form(Schema $schema): Schema
    {
        $personSelect = fn (string $field, string $label): Select => Select::make($field)
            ->label($label)
            ->relationship(
                $field === 'partner_one_id' ? 'partnerOne' : 'partnerTwo',
                'last_name',
            )
            ->getOptionLabelFromRecordUsing(fn (Person $record): string => $record->full_name)
            ->searchable(['first_name', 'middle_name', 'last_name'])
            ->preload()
            ->required();

        return $schema->components([
            $personSelect('partner_one_id', 'Первый человек')
                ->default(fn (): int => $this->getOwnerRecord()->getKey()),
            $personSelect('partner_two_id', 'Второй человек'),
            Select::make('status')
                ->label('Статус')
                ->options([
                    'married' => 'В браке',
                    'partners' => 'Партнёры',
                    'divorced' => 'Разведены',
                    'widowed' => 'Вдовство',
                ])
                ->default('married')
                ->required(),
            DatePicker::make('started_at')
                ->label('Дата начала / свадьбы')
                ->native(false),
            DatePicker::make('ended_at')
                ->label('Дата окончания')
                ->native(false),
            TextInput::make('place')->label('Место'),
            Textarea::make('notes')->label('Примечание')->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        $ownerId = $this->getOwnerRecord()->getKey();

        return $table
            ->query(
                Partnership::query()
                    ->where('partner_one_id', $ownerId)
                    ->orWhere('partner_two_id', $ownerId),
            )
            ->columns([
                TextColumn::make('partner_name')
                    ->label('Супруг / партнёр')
                    ->state(function (Partnership $record) use ($ownerId): string {
                        return $record->partner_one_id === $ownerId
                            ? $record->partnerTwo->full_name
                            : $record->partnerOne->full_name;
                    }),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'married' => 'В браке',
                        'partners' => 'Партнёры',
                        'divorced' => 'Разведены',
                        'widowed' => 'Вдовство',
                        default => $state,
                    }),
                TextColumn::make('started_at')->label('Начало')->date('d.m.Y'),
                TextColumn::make('ended_at')->label('Окончание')->date('d.m.Y'),
            ])
            ->headerActions([
                CreateAction::make()->label('Добавить супруга / партнёра'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
