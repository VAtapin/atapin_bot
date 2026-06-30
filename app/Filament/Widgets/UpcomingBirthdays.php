<?php

namespace App\Filament\Widgets;

use App\Models\Person;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class UpcomingBirthdays extends TableWidget
{
    protected static ?string $heading = 'Дни рождения в этом месяце';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Person::query()
                ->where('is_published', true)
                ->whereNull('death_date')
                ->whereMonth('birth_date', now()->month)
                ->orderByRaw(match (DB::connection()->getDriverName()) {
                    'sqlite' => "CAST(strftime('%d', birth_date) AS INTEGER)",
                    'pgsql' => 'EXTRACT(DAY FROM birth_date)',
                    default => 'DAY(birth_date)',
                }))
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('Фото')
                    ->disk('public')
                    ->circular(),
                TextColumn::make('full_name')
                    ->label('Человек'),
                TextColumn::make('birth_date')
                    ->label('Дата')
                    ->date('d F'),
                TextColumn::make('age')
                    ->label('Исполняется')
                    ->suffix(' лет'),
            ])
            ->paginated(false);
    }
}
