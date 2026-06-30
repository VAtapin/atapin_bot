<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportGedcom extends Command
{
    protected $signature = 'gedcom:import {file} {--fresh} {--photos}';

    protected $description = 'Import GEDCOM into family archive';

    private array $people = [];

    private array $families = [];

    private array $idMap = [];

    private int $photosFound = 0;

    private int $photosDownloaded = 0;

    private int $photosFailed = 0;

    public function handle(): int
    {
        DB::statement('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

        $file = (string) $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $this->parseGedcom($file);

        $this->info('People found: '.count($this->people));
        $this->info('Families found: '.count($this->families));
        $this->info('Photos found: '.$this->photosFound);

        if ($this->option('fresh')) {
            $this->freshDatabase();
        }

        DB::transaction(function (): void {
            $this->importPeople();
            $this->importFamilies();
        });

        $this->newLine();
        $this->info('Import completed.');
        $this->line('People imported: '.count($this->idMap));
        $this->line('Families imported: '.count($this->families));
        $this->line('Photos downloaded: '.$this->photosDownloaded);
        $this->line('Photos failed: '.$this->photosFailed);

        return self::SUCCESS;
    }

    private function freshDatabase(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('family_events')->delete();
        DB::table('parent_children')->delete();
        DB::table('partnerships')->delete();

        if (DB::getSchemaBuilder()->hasTable('telegram_users')) {
            DB::table('telegram_users')->update(['person_id' => null]);
        }

        DB::table('people')->delete();

        DB::statement('ALTER TABLE people AUTO_INCREMENT = 1');
        DB::statement('ALTER TABLE parent_children AUTO_INCREMENT = 1');
        DB::statement('ALTER TABLE partnerships AUTO_INCREMENT = 1');
        DB::statement('ALTER TABLE family_events AUTO_INCREMENT = 1');

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function parseGedcom(string $file): void
    {
        $handle = fopen($file, 'rb');

        if (! $handle) {
            throw new \RuntimeException("Cannot open file: {$file}");
        }

        $currentType = null;
        $currentId = null;
        $currentEvent = null;
        $currentPhoto = false;
        $lastTextField = null;

        while (($line = fgets($handle)) !== false) {
            $line = $this->cleanLine($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^0 @([^@]+)@ (INDI|FAM)$/', $line, $m)) {
                $currentId = $m[1];
                $currentType = $m[2];
                $currentEvent = null;
                $currentPhoto = false;
                $lastTextField = null;

                if ($currentType === 'INDI') {
                    $this->people[$currentId] = [
                        'gedcom_id' => $currentId,
                        'name' => null,
                        'sex' => null,
                        'birth_date' => null,
                        'birth_place' => null,
                        'death_date' => null,
                        'death_place' => null,
                        'occupation' => null,
                        'note' => null,
                        'photo' => null,
                    ];
                }

                if ($currentType === 'FAM') {
                    $this->families[$currentId] = [
                        'gedcom_id' => $currentId,
                        'husb' => null,
                        'wife' => null,
                        'children' => [],
                        'marriage_date' => null,
                        'marriage_place' => null,
                        'divorce_date' => null,
                    ];
                }

                continue;
            }

            if (! $currentId || ! $currentType) {
                continue;
            }

            if ($currentType === 'INDI') {
                $this->parsePersonLine(
                    $currentId,
                    $line,
                    $currentEvent,
                    $currentPhoto,
                    $lastTextField
                );
            }

            if ($currentType === 'FAM') {
                $this->parseFamilyLine(
                    $currentId,
                    $line,
                    $currentEvent,
                    $lastTextField
                );
            }
        }

        fclose($handle);
    }

    private function parsePersonLine(
        string $id,
        string $line,
        ?string &$currentEvent,
        bool &$currentPhoto,
        ?string &$lastTextField
    ): void {
        if (preg_match('/^1 NAME (.+)$/', $line, $m)) {
            $this->people[$id]['name'] = $this->cleanText($m[1]);
            $lastTextField = 'name';

            return;
        }

        if (preg_match('/^1 SEX (.+)$/', $line, $m)) {
            $this->people[$id]['sex'] = $this->cleanText($m[1]);

            return;
        }

        if (preg_match('/^1 OCCU (.+)$/', $line, $m)) {
            $this->people[$id]['occupation'] = $this->cleanText($m[1]);
            $lastTextField = 'occupation';

            return;
        }

        if (preg_match('/^1 NOTE ?(.*)$/', $line, $m)) {
            $this->people[$id]['note'] = $this->cleanText($m[1] ?? '');
            $lastTextField = 'note';

            return;
        }

        if (preg_match('/^2 CONT ?(.*)$/', $line, $m)) {
            if ($lastTextField === 'note') {
                $this->people[$id]['note'] .= "\n".$this->cleanText($m[1] ?? '');
            }

            return;
        }

        if (preg_match('/^2 CONC ?(.*)$/', $line, $m)) {
            if ($lastTextField === 'note') {
                $this->people[$id]['note'] .= $this->cleanText($m[1] ?? '');
            }

            return;
        }

        if (preg_match('/^1 BIRT/', $line)) {
            $currentEvent = 'birth';
            $currentPhoto = false;

            return;
        }

        if (preg_match('/^1 DEAT/', $line)) {
            $currentEvent = 'death';
            $currentPhoto = false;

            return;
        }

        if (preg_match('/^1 OBJE/', $line)) {
            $currentPhoto = true;
            $currentEvent = null;

            return;
        }

        if ($currentPhoto && preg_match('/^2 FILE (https?:\/\/.+)$/', $line, $m)) {
            if (! $this->people[$id]['photo']) {
                $this->people[$id]['photo'] = $this->cleanText($m[1]);
                $this->photosFound++;
            }

            return;
        }

        if ($currentEvent && preg_match('/^2 DATE (.+)$/', $line, $m)) {
            $this->people[$id][$currentEvent.'_date'] = $this->parseDate($m[1]);

            return;
        }

        if ($currentEvent && preg_match('/^2 PLAC (.+)$/', $line, $m)) {
            $this->people[$id][$currentEvent.'_place'] = $this->cleanText($m[1]);

            return;
        }

        if (preg_match('/^1 [A-Z_]+/', $line)) {
            $currentEvent = null;
            $currentPhoto = false;
            $lastTextField = null;
        }
    }

    private function parseFamilyLine(
        string $id,
        string $line,
        ?string &$currentEvent,
        ?string &$lastTextField
    ): void {
        if (preg_match('/^1 HUSB @([^@]+)@/', $line, $m)) {
            $this->families[$id]['husb'] = $m[1];

            return;
        }

        if (preg_match('/^1 WIFE @([^@]+)@/', $line, $m)) {
            $this->families[$id]['wife'] = $m[1];

            return;
        }

        if (preg_match('/^1 CHIL @([^@]+)@/', $line, $m)) {
            $this->families[$id]['children'][] = $m[1];

            return;
        }

        if (preg_match('/^1 MARR/', $line)) {
            $currentEvent = 'marriage';

            return;
        }

        if (preg_match('/^1 DIV/', $line)) {
            $currentEvent = 'divorce';

            return;
        }

        if ($currentEvent === 'marriage' && preg_match('/^2 DATE (.+)$/', $line, $m)) {
            $this->families[$id]['marriage_date'] = $this->parseDate($m[1]);

            return;
        }

        if ($currentEvent === 'marriage' && preg_match('/^2 PLAC (.+)$/', $line, $m)) {
            $this->families[$id]['marriage_place'] = $this->cleanText($m[1]);

            return;
        }

        if ($currentEvent === 'divorce' && preg_match('/^2 DATE (.+)$/', $line, $m)) {
            $this->families[$id]['divorce_date'] = $this->parseDate($m[1]);

            return;
        }

        if (preg_match('/^1 [A-Z_]+/', $line)) {
            $currentEvent = null;
            $lastTextField = null;
        }
    }

    private function importPeople(): void
    {
        $bar = $this->output->createProgressBar(count($this->people));
        $bar->start();

        foreach ($this->people as $gedcomId => $person) {
            [$firstName, $middleName, $lastName] = $this->splitName($person['name']);

            $photoPath = null;

            if ($this->option('photos') && $person['photo']) {
                $photoPath = $this->downloadPhoto($person['photo'], $gedcomId);
            }

            $personId = DB::table('people')->insertGetId([
                'first_name' => $this->cleanText($firstName ?: 'Без имени'),
                'middle_name' => $this->cleanText($middleName),
                'last_name' => $this->cleanText($lastName ?: 'Без фамилии'),
                'maiden_name' => null,
                'gender' => match ($person['sex']) {
                    'M' => 'male',
                    'F' => 'female',
                    default => 'unknown',
                },
                'birth_date' => $person['birth_date'],
                'death_date' => $person['death_date'],
                'birth_place' => $this->cleanText($person['birth_place']),
                'current_city' => null,
                'occupation' => $this->cleanText($person['occupation']),
                'bio' => $this->cleanText($person['note']),
                'photo_path' => $this->cleanText($photoPath),
                'is_published' => 1,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->idMap[$gedcomId] = $personId;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    private function importFamilies(): void
    {
        foreach ($this->families as $family) {
            $husbandId = $family['husb'] ? ($this->idMap[$family['husb']] ?? null) : null;
            $wifeId = $family['wife'] ? ($this->idMap[$family['wife']] ?? null) : null;

            $parents = array_values(array_filter([$husbandId, $wifeId]));

            if (count($parents) === 2) {
                DB::table('partnerships')->insert([
                    'partner_one_id' => $parents[0],
                    'partner_two_id' => $parents[1],
                    'status' => $family['divorce_date'] ? 'divorced' : 'married',
                    'started_at' => $family['marriage_date'],
                    'ended_at' => $family['divorce_date'],
                    'place' => $this->cleanText($family['marriage_place']),
                    'notes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($family['children'] as $childGedcomId) {
                $childId = $this->idMap[$childGedcomId] ?? null;

                if (! $childId) {
                    continue;
                }

                foreach ($parents as $parentId) {
                    DB::table('parent_children')->insert([
                        'parent_id' => $parentId,
                        'child_id' => $childId,
                        'type' => 'biological',
                        'notes' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function splitName(?string $name): array
    {
        $name = $this->cleanText($name);

        if (! $name) {
            return [null, null, null];
        }

        $name = str_replace('/', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim($name);

        $parts = explode(' ', $name);

        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }

        if (count($parts) === 2) {
            return [$parts[0], null, $parts[1]];
        }

        $firstName = array_shift($parts);
        $lastName = array_pop($parts);
        $middleName = implode(' ', $parts);

        return [$firstName, $middleName ?: null, $lastName];
    }

    private function parseDate(?string $date): ?string
    {
        $date = $this->cleanText($date);

        if (! $date) {
            return null;
        }

        $date = strtoupper($date);

        $date = preg_replace('/^(ABT|ABOUT|BEF|BEFORE|AFT|AFTER|CAL|EST)\s+/i', '', $date);
        $date = trim($date);

        $months = [
            'JAN' => '01',
            'FEB' => '02',
            'MAR' => '03',
            'APR' => '04',
            'MAY' => '05',
            'JUN' => '06',
            'JUL' => '07',
            'AUG' => '08',
            'SEP' => '09',
            'OCT' => '10',
            'NOV' => '11',
            'DEC' => '12',
        ];

        if (preg_match('/^(\d{1,2}) ([A-Z]{3}) (\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) ($months[$m[2]] ?? 1), (int) $m[1]);
        }

        if (preg_match('/^([A-Z]{3}) (\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-01', (int) $m[2], (int) ($months[$m[1]] ?? 1));
        }

        if (preg_match('/^(\d{4})$/', $date, $m)) {
            return "{$m[1]}-01-01";
        }

        return null;
    }

    private function downloadPhoto(string $url, string $gedcomId): ?string
    {
        try {
            $response = Http::timeout(30)->retry(2, 500)->get($url);

            if (! $response->successful()) {
                $this->photosFailed++;

                return null;
            }

            $extension = $this->guessImageExtension(
                $response->header('Content-Type'),
                $url
            );

            $fileName = Str::slug($gedcomId).'.'.$extension;
            $path = 'people/photos/'.$fileName;

            Storage::disk('public')->put($path, $response->body());

            $this->photosDownloaded++;

            return $path;
        } catch (Throwable) {
            $this->photosFailed++;

            return null;
        }
    }

    private function guessImageExtension(?string $contentType, string $url): string
    {
        $contentType = strtolower((string) $contentType);

        if (str_contains($contentType, 'png')) {
            return 'png';
        }

        if (str_contains($contentType, 'webp')) {
            return 'webp';
        }

        if (str_contains($contentType, 'gif')) {
            return 'gif';
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                return $extension === 'jpeg' ? 'jpg' : $extension;
            }
        }

        return 'jpg';
    }

    private function cleanLine(string $line): string
    {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
        $line = str_replace(["\r\n", "\r"], "\n", $line);
        $line = trim($line, "\n");

        return $this->cleanText($line) ?? '';
    }

    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1251, ISO-8859-1, UTF-8');
        }

        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($value === false) {
            return null;
        }

        return trim($value);
    }
}