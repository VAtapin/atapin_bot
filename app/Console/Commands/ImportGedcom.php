<?php

namespace App\Console\Commands;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ImportGedcom extends Command
{
    protected $signature = 'gedcom:import
        {file : Path to a GEDCOM file}
        {--fresh : Replace all people and family links}
        {--photos : Download all GEDCOM photos}
        {--dry-run : Parse and report without changing the database}';

    protected $description = 'Import people, families, places and all raw facts from GEDCOM';

    private array $people = [];

    private array $families = [];

    private array $idMap = [];

    private ?array $existingPhotoFiles = null;

    private array $stats = [
        'residences' => 0,
        'residence_cities' => 0,
        'birth_places' => 0,
        'death_places' => 0,
        'burial_places' => 0,
        'photos' => 0,
        'photos_downloaded' => 0,
        'photos_reused' => 0,
        'photos_failed' => 0,
    ];

    public function handle(): int
    {
        $file = $this->resolveFile((string) $this->argument('file'));

        if (! is_file($file)) {
            $this->error("Файл не найден: {$file}");

            return self::FAILURE;
        }

        $this->parseGedcom($file);
        $this->report();

        if ($this->option('dry-run')) {
            $this->info('Проверка завершена, база данных не изменялась.');

            return self::SUCCESS;
        }

        if (
            ! $this->option('fresh')
            && Person::query()->exists()
            && ! Person::query()->whereNotNull('gedcom_id')->exists()
        ) {
            $this->error('В базе уже есть люди из старого импорта без GEDCOM ID.');
            $this->line('Сделайте резервную копию и выполните первый новый импорт с --fresh.');

            return self::FAILURE;
        }

        DB::transaction(function (): void {
            if ($this->option('fresh')) {
                $this->freshDatabase();
            }

            $this->importPeople();
            $this->importFamilies();
        });

        $this->newLine();
        $this->info('Импорт завершён.');
        $this->line('Людей сохранено: '.count($this->idMap));
        $this->line('Семей обработано: '.count($this->families));
        $this->line('Фотографий загружено: '.$this->stats['photos_downloaded']);
        $this->line('Готовых файлов использовано повторно: '.$this->stats['photos_reused']);
        $this->line('Ошибок фотографий: '.$this->stats['photos_failed']);

        return self::SUCCESS;
    }

    private function resolveFile(string $file): string
    {
        if (is_file($file)) {
            return realpath($file) ?: $file;
        }

        $storagePath = storage_path('app/import/'.ltrim($file, '/\\'));

        return is_file($storagePath) ? $storagePath : $file;
    }

    private function parseGedcom(string $file): void
    {
        $handle = fopen($file, 'rb');

        if (! $handle) {
            throw new RuntimeException("Невозможно открыть GEDCOM: {$file}");
        }

        $recordType = null;
        $recordId = null;
        $recordLines = [];

        while (($line = fgets($handle)) !== false) {
            $line = $this->cleanLine($line);

            if (preg_match('/^0 @([^@]+)@ (INDI|FAM)$/', $line, $matches)) {
                $this->finishRecord($recordType, $recordId, $recordLines);
                $recordId = $matches[1];
                $recordType = $matches[2];
                $recordLines = [];

                continue;
            }

            if (str_starts_with($line, '0 ')) {
                $this->finishRecord($recordType, $recordId, $recordLines);
                $recordType = null;
                $recordId = null;
                $recordLines = [];

                continue;
            }

            if ($recordType && $line !== '') {
                $recordLines[] = $line;
            }
        }

        $this->finishRecord($recordType, $recordId, $recordLines);
        fclose($handle);
    }

    private function finishRecord(?string $type, ?string $id, array $lines): void
    {
        if (! $type || ! $id) {
            return;
        }

        if ($type === 'INDI') {
            $this->people[$id] = $this->parsePerson($id, $lines);

            return;
        }

        $this->families[$id] = $this->parseFamily($id, $lines);
    }

    private function parsePerson(string $id, array $lines): array
    {
        $person = [
            'gedcom_id' => $id,
            'name' => null,
            'given_name' => null,
            'surname' => null,
            'married_name' => null,
            'sex' => null,
            'events' => [],
            'residences' => [],
            'notes' => [],
            'occupations' => [],
            'photos' => [],
            'raw' => $lines,
        ];
        $context = null;
        $contextIndex = null;
        $textTarget = null;

        foreach ($lines as $line) {
            [$level, $tag, $value] = $this->lineParts($line);

            if ($level === null || $tag === null) {
                continue;
            }

            if ($level === 1) {
                $context = $tag;
                $contextIndex = null;
                $textTarget = null;

                match ($tag) {
                    'NAME' => $person['name'] = $value,
                    'SEX' => $person['sex'] = $value,
                    'NOTE' => $this->startText($person['notes'], $value, $contextIndex, $textTarget, 'notes'),
                    'OCCU' => $this->startText($person['occupations'], $value, $contextIndex, $textTarget, 'occupations'),
                    'RESI' => $this->startEvent($person['residences'], $value, $contextIndex),
                    'OBJE' => $this->startEvent($person['photos'], $value, $contextIndex),
                    default => $this->startTaggedEvent($person['events'], $tag, $value, $contextIndex),
                };

                continue;
            }

            if ($context === 'NAME' && $level === 2) {
                match ($tag) {
                    'GIVN' => $person['given_name'] = $value,
                    'SURN' => $person['surname'] = $value,
                    '_MARNM' => $person['married_name'] = $value,
                    default => null,
                };

                continue;
            }

            if (in_array($tag, ['CONT', 'CONC'], true) && $textTarget) {
                $separator = $tag === 'CONT' ? "\n" : '';
                $person[$textTarget][$contextIndex] .= $separator.$value;

                continue;
            }

            if ($context === 'RESI' && $contextIndex !== null) {
                $this->addEventValue($person['residences'][$contextIndex], $tag, $value);

                continue;
            }

            if ($context === 'OBJE' && $contextIndex !== null) {
                $this->addEventValue($person['photos'][$contextIndex], $tag, $value);

                continue;
            }

            if (isset($person['events'][$context]) && $contextIndex !== null) {
                $this->addEventValue($person['events'][$context][$contextIndex], $tag, $value);
            }
        }

        $this->stats['residences'] += count($person['residences']);
        $this->stats['residence_cities'] += collect($person['residences'])->whereNotNull('CITY')->count();
        $this->stats['birth_places'] += $this->eventValue($person, 'BIRT', 'PLAC') ? 1 : 0;
        $this->stats['death_places'] += $this->eventValue($person, 'DEAT', 'PLAC') ? 1 : 0;
        $this->stats['burial_places'] += $this->eventValue($person, 'BURI', 'PLAC') ? 1 : 0;
        $this->stats['photos'] += collect($person['photos'])->whereNotNull('FILE')->count();

        return $person;
    }

    private function parseFamily(string $id, array $lines): array
    {
        $family = [
            'gedcom_id' => $id,
            'husband' => null,
            'wife' => null,
            'children' => [],
            'events' => [],
            'raw' => $lines,
        ];
        $context = null;
        $contextIndex = null;

        foreach ($lines as $line) {
            [$level, $tag, $value] = $this->lineParts($line);

            if ($level === null || $tag === null) {
                continue;
            }

            if ($level === 1) {
                $context = $tag;
                $contextIndex = null;

                if ($tag === 'HUSB') {
                    $family['husband'] = $this->pointer($value);
                } elseif ($tag === 'WIFE') {
                    $family['wife'] = $this->pointer($value);
                } elseif ($tag === 'CHIL') {
                    $family['children'][] = $this->pointer($value);
                } else {
                    $this->startTaggedEvent($family['events'], $tag, $value, $contextIndex);
                }

                continue;
            }

            if (isset($family['events'][$context]) && $contextIndex !== null) {
                $this->addEventValue($family['events'][$context][$contextIndex], $tag, $value);
            }
        }

        return $family;
    }

    private function lineParts(string $line): array
    {
        if (! preg_match('/^(\d+) ([^ ]+)(?: (.*))?$/', $line, $matches)) {
            return [null, null, null];
        }

        return [(int) $matches[1], $matches[2], $this->cleanText($matches[3] ?? null)];
    }

    private function startEvent(array &$events, ?string $value, ?int &$index): void
    {
        $events[] = array_filter(['value' => $value], fn (mixed $item): bool => $item !== null && $item !== '');
        $index = array_key_last($events);
    }

    private function startTaggedEvent(
        array &$events,
        string $tag,
        ?string $value,
        ?int &$index,
    ): void {
        $events[$tag] ??= [];
        $this->startEvent($events[$tag], $value, $index);
    }

    private function startText(
        array &$items,
        ?string $value,
        ?int &$index,
        ?string &$target,
        string $targetName,
    ): void {
        $items[] = $value ?? '';
        $index = array_key_last($items);
        $target = $targetName;
    }

    private function addEventValue(array &$event, string $tag, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (isset($event[$tag])) {
            $event[$tag] = (array) $event[$tag];
            $event[$tag][] = $value;

            return;
        }

        $event[$tag] = $value;
    }

    private function importPeople(): void
    {
        $bar = $this->output->createProgressBar(count($this->people));
        $bar->start();

        foreach ($this->people as $gedcomId => $source) {
            [$firstName, $middleName, $lastName] = $this->personName($source);
            $residence = $this->currentResidence($source['residences']);
            $birthDate = $this->eventDate($source, 'BIRT');
            $person = Person::withTrashed()->firstOrNew(['gedcom_id' => $gedcomId]);

            $photoPath = $person->photo_path;

            $person->fill(
                [
                    'gedcom_id' => $gedcomId,
                    'first_name' => $firstName ?: 'Без имени',
                    'middle_name' => $middleName,
                    'last_name' => $lastName ?: 'Без фамилии',
                    'maiden_name' => $source['married_name'] ? $lastName : null,
                    'married_name' => $source['married_name'],
                    'gender' => match ($source['sex']) {
                        'M' => 'male',
                        'F' => 'female',
                        default => 'unknown',
                    },
                    'birth_date' => $birthDate,
                    'death_date' => $this->eventDate($source, 'DEAT'),
                    'birth_place' => $this->eventValue($source, 'BIRT', 'PLAC'),
                    'death_place' => $this->eventValue($source, 'DEAT', 'PLAC'),
                    'burial_place' => $this->eventValue($source, 'BURI', 'PLAC'),
                    'current_city' => $residence['CITY'] ?? $residence['PLAC'] ?? null,
                    'current_address' => $this->residenceAddress($residence),
                    'occupation' => collect($source['occupations'])->filter()->implode('; ') ?: null,
                    'bio' => collect($source['notes'])->filter()->implode("\n\n") ?: null,
                    'photo_path' => $photoPath,
                    'gedcom_data' => [
                        'name' => $source['name'],
                        'events' => $source['events'],
                        'residences' => $source['residences'],
                        'photos' => $source['photos'],
                        'raw' => $source['raw'],
                    ],
                    'imported_at' => now(),
                    'is_published' => ! $this->isUnassociatedPhotosRecord($gedcomId, $source),
                ],
            );
            $person->save();
            $this->syncPersonPhotos($person, $source['photos'], $gedcomId);

            if ($person->trashed()) {
                $person->restore();
            }

            $this->idMap[$gedcomId] = $person->id;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function importFamilies(): void
    {
        $importedIds = array_values($this->idMap);

        ParentChild::query()
            ->whereIn('parent_id', $importedIds)
            ->whereIn('child_id', $importedIds)
            ->delete();
        Partnership::query()
            ->whereIn('partner_one_id', $importedIds)
            ->whereIn('partner_two_id', $importedIds)
            ->delete();

        foreach ($this->families as $family) {
            $parents = collect([$family['husband'], $family['wife']])
                ->filter()
                ->map(fn (string $gedcomId): ?int => $this->idMap[$gedcomId] ?? null)
                ->filter()
                ->values();

            if ($parents->count() === 2) {
                $partners = $parents->sort()->values();
                $divorceDate = $this->familyEventDate($family, 'DIV');

                Partnership::query()->updateOrCreate(
                    [
                        'partner_one_id' => $partners[0],
                        'partner_two_id' => $partners[1],
                    ],
                    [
                        'status' => $divorceDate ? 'divorced' : 'married',
                        'started_at' => $this->familyEventDate($family, 'MARR'),
                        'ended_at' => $divorceDate,
                        'place' => $this->familyEventValue($family, 'MARR', 'PLAC'),
                        'notes' => json_encode([
                            'gedcom_id' => $family['gedcom_id'],
                            'raw' => $family['raw'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                );
            }

            foreach ($family['children'] as $childGedcomId) {
                $childId = $this->idMap[$childGedcomId] ?? null;

                if (! $childId) {
                    continue;
                }

                foreach ($parents as $parentId) {
                    ParentChild::query()->updateOrCreate(
                        ['parent_id' => $parentId, 'child_id' => $childId],
                        ['type' => 'biological'],
                    );
                }
            }
        }
    }

    private function freshDatabase(): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            DB::table('family_events')->delete();
            DB::table('person_photos')->delete();
            DB::table('photo_albums')->delete();
            DB::table('parent_children')->delete();
            DB::table('partnerships')->delete();
            DB::table('telegram_users')->update(['person_id' => null]);
            DB::table('people')->delete();
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function personName(array $person): array
    {
        $given = $person['given_name'];
        $surname = $person['surname'];

        if (! $given || ! $surname) {
            $name = trim((string) $person['name']);

            if (preg_match('/^(.*?)\s*\/(.*?)\/$/u', $name, $matches)) {
                $given ??= trim($matches[1]);
                $surname ??= trim($matches[2]);
            }
        }

        $givenParts = preg_split('/\s+/u', trim((string) $given), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $firstName = array_shift($givenParts);

        return [$firstName, $givenParts ? implode(' ', $givenParts) : null, $surname];
    }

    private function currentResidence(array $residences): array
    {
        $filled = collect($residences)->filter(
            fn (array $residence): bool => collect($residence)
                ->only(['CITY', 'PLAC', 'ADR1', 'ADDR', 'CTRY', 'STAE', 'POST'])
                ->filter()
                ->isNotEmpty(),
        );

        return $filled->first(
            fn (array $residence): bool => str_contains(
                mb_strtolower((string) ($residence['NOTE'] ?? '')),
                'current address:1',
            ),
        ) ?? $filled->last() ?? [];
    }

    private function residenceAddress(array $residence): ?string
    {
        $parts = collect(['ADR1', 'ADDR', 'CITY', 'STAE', 'POST', 'CTRY'])
            ->map(fn (string $key): mixed => $residence[$key] ?? null)
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        return $parts->isEmpty() ? null : $parts->implode(', ');
    }

    private function eventDate(array $person, string $tag): ?string
    {
        return $this->parseDate($this->eventValue($person, $tag, 'DATE'));
    }

    private function eventValue(array $person, string $tag, string $field): ?string
    {
        $value = $person['events'][$tag][0][$field] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    private function familyEventDate(array $family, string $tag): ?string
    {
        return $this->parseDate($this->familyEventValue($family, $tag, 'DATE'));
    }

    private function familyEventValue(array $family, string $tag, string $field): ?string
    {
        $value = $family['events'][$tag][0][$field] ?? null;

        return is_array($value) ? ($value[0] ?? null) : $value;
    }

    private function parseDate(?string $date): ?string
    {
        $date = mb_strtoupper(trim((string) $date));

        if ($date === '') {
            return null;
        }

        $date = preg_replace('/^(ABT|ABOUT|BEF|BEFORE|AFT|AFTER|CAL|EST)\s+/u', '', $date);
        $months = [
            'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4,
            'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8,
            'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        ];

        if (preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $months[$matches[2]] ?? 1, $matches[1]);
        }

        if (preg_match('/^([A-Z]{3}) (\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-01', $matches[2], $months[$matches[1]] ?? 1);
        }

        return preg_match('/^\d{4}$/', $date) ? "{$date}-01-01" : null;
    }

    private function primaryPhoto(array $photos): ?string
    {
        $images = collect($photos)->filter(function (array $photo): bool {
            $file = (string) ($photo['FILE'] ?? '');

            return str_starts_with($file, 'http')
                && ! str_ends_with(mb_strtolower(parse_url($file, PHP_URL_PATH) ?: ''), '.pdf');
        });

        $photo = $images->first(
            fn (array $item): bool => ($item['_PRIM'] ?? null) === 'Y'
                || ($item['_PERSONALPHOTO'] ?? null) === 'Y',
        ) ?? $images->first();

        return $photo['FILE'] ?? null;
    }

    private function syncPersonPhotos(Person $person, array $photos, string $gedcomId): void
    {
        $images = collect($photos)
            ->filter(fn (array $photo): bool => $this->isImagePhoto($photo))
            ->values();

        if ($images->isEmpty()) {
            return;
        }

        $primaryUrl = $this->primaryPhoto($images->all());
        $primaryPath = null;

        foreach ($images as $index => $photo) {
            $url = (string) $photo['FILE'];
            $path = null;
            $record = PersonPhoto::query()->firstOrNew([
                'gedcom_key' => $gedcomId.':'.($index + 1),
            ]);

            if ($this->option('photos')) {
                $path = $this->existingPhotoPath($record, $gedcomId, $index + 1)
                    ?: $this->downloadPhoto($url, $gedcomId, $index + 1);
            }

            $isPrimary = $url === $primaryUrl;
            $record->fill([
                'person_id' => $person->id,
                'photo_album_id' => null,
                'path' => $path ?? $record->path,
                'source_url' => $url,
                'title' => $photo['TITL'] ?? null,
                'is_primary' => $isPrimary,
                'sort_order' => $index,
                'gedcom_data' => $photo,
            ])->save();

            if ($isPrimary && $record->path) {
                $primaryPath = $record->path;
            }
        }

        PersonPhoto::query()
            ->where('person_id', $person->id)
            ->whereNotNull('gedcom_key')
            ->whereNotIn(
                'gedcom_key',
                $images->keys()->map(fn (int $index): string => $gedcomId.':'.($index + 1)),
            )
            ->delete();

        if ($primaryPath) {
            $person->updateQuietly(['photo_path' => $primaryPath]);
        }
    }

    private function isUnassociatedPhotosRecord(string $gedcomId, array $source): bool
    {
        return $gedcomId === 'I88888888'
            || mb_strtolower(trim((string) $source['given_name'])) === 'unassociated photos';
    }

    private function isImagePhoto(array $photo): bool
    {
        $file = (string) ($photo['FILE'] ?? '');

        return str_starts_with($file, 'http')
            && ! str_ends_with(mb_strtolower(parse_url($file, PHP_URL_PATH) ?: ''), '.pdf');
    }

    private function downloadPhoto(string $url, string $gedcomId, int $index): ?string
    {
        try {
            $response = Http::timeout(30)->retry(2, 500)->get($url);

            if (! $response->successful()) {
                $this->stats['photos_failed']++;

                return null;
            }

            $extension = $this->imageExtension($response->header('Content-Type'), $url);
            $path = 'people/photos/'
                .Str::slug($gedcomId).'-'
                .str_pad((string) $index, 3, '0', STR_PAD_LEFT)
                .'.'.$extension;
            Storage::disk('public')->put($path, $response->body());
            $this->stats['photos_downloaded']++;

            return $path;
        } catch (Throwable) {
            $this->stats['photos_failed']++;

            return null;
        }
    }

    private function existingPhotoPath(PersonPhoto $photo, string $gedcomId, int $index): ?string
    {
        if ($photo->path && Storage::disk('public')->exists($photo->path)) {
            $this->stats['photos_reused']++;

            return $photo->path;
        }

        $prefix = Str::slug($gedcomId).'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $this->existingPhotoFiles ??= collect(Storage::disk('public')->files('people/photos'))
            ->mapWithKeys(fn (string $path): array => [pathinfo($path, PATHINFO_FILENAME) => $path])
            ->all();
        $file = $this->existingPhotoFiles[$prefix] ?? null;

        if ($file) {
            $this->stats['photos_reused']++;
        }

        return $file;
    }

    private function imageExtension(?string $contentType, string $url): string
    {
        $contentType = mb_strtolower((string) $contentType);

        foreach (['png', 'webp', 'gif'] as $extension) {
            if (str_contains($contentType, $extension)) {
                return $extension;
            }
        }

        $extension = mb_strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : 'jpg';
    }

    private function pointer(?string $value): ?string
    {
        return preg_match('/^@([^@]+)@$/', (string) $value, $matches) ? $matches[1] : null;
    }

    private function cleanLine(string $line): string
    {
        return $this->cleanText(rtrim($line, "\r\n")) ?? '';
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1251, ISO-8859-1');
        }

        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return $value === false ? null : trim($value);
    }

    private function report(): void
    {
        $expectedPartnerships = collect($this->families)->filter(
            fn (array $family): bool => $family['husband'] && $family['wife'],
        )->count();
        $expectedParentLinks = collect($this->families)->sum(function (array $family): int {
            $parentCount = (int) (bool) $family['husband'] + (int) (bool) $family['wife'];

            return count($family['children']) * $parentCount;
        });

        $this->info('Найдено людей: '.count($this->people));
        $this->info('Найдено семей: '.count($this->families));
        $this->line('Ожидаемых союзов с двумя партнёрами: '.$expectedPartnerships);
        $this->line('Ожидаемых связей родитель — ребёнок: '.$expectedParentLinks);
        $this->line('Мест рождения: '.$this->stats['birth_places']);
        $this->line('Мест смерти: '.$this->stats['death_places']);
        $this->line('Мест захоронения: '.$this->stats['burial_places']);
        $this->line('Записей проживания: '.$this->stats['residences']);
        $this->line('Явных полей CITY: '.$this->stats['residence_cities']);
        $this->line('Ссылок на фотографии: '.$this->stats['photos']);

        if ($this->stats['residence_cities'] < $this->stats['residences']) {
            $this->warn('В GEDCOM у большинства RESI отсутствует CITY; импортёр сохраняет доступные PLAC/ADDR, но не придумывает города.');
        }

        if (Schema::hasTable('people')) {
            $this->newLine();
            $this->line(
                'Сейчас в базе: '.Person::query()->count().' людей, '
                .Partnership::query()->count().' союзов, '
                .ParentChild::query()->count().' родительских связей, '
                .Person::query()->whereNotNull('current_city')->count().' заполненных городов.',
            );

            if (Schema::hasColumn('people', 'gedcom_id')) {
                $importedIds = Person::query()
                    ->whereNotNull('gedcom_id')
                    ->pluck('gedcom_id')
                    ->all();
                $missing = array_diff(array_keys($this->people), $importedIds);

                if ($importedIds !== []) {
                    $this->line('GEDCOM ID в базе: '.count($importedIds).'; записей файла нет в базе: '.count($missing).'.');
                }
            }
        }
    }
}
