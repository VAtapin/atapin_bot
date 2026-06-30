<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportGedcom extends Command
{
    protected $signature = 'gedcom:import {file} {--fresh} {--photos}';
    protected $description = 'Import GEDCOM into family archive';

    private array $people = [];
    private array $families = [];
    private array $idMap = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! file_exists($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $this->parseGedcom(file($file, FILE_IGNORE_NEW_LINES));

        $this->info('People found: '.count($this->people));
        $this->info('Families found: '.count($this->families));

    if ($this->option('fresh')) {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        DB::table('family_events')->delete();
        DB::table('parent_children')->delete();
        DB::table('partnerships')->delete();
        DB::table('telegram_users')->update(['person_id' => null]);
        DB::table('people')->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

        DB::transaction(function () {
            $this->importPeople();
            $this->importFamilies();
        });

        $this->info('Import completed.');

        return self::SUCCESS;
    }

    private function parseGedcom(array $lines): void
    {
        $currentType = null;
        $currentId = null;

        foreach ($lines as $line) {
            if (preg_match('/^0 @([^@]+)@ (INDI|FAM)/', $line, $m)) {
                $currentId = $m[1];
                $currentType = $m[2];

                if ($currentType === 'INDI') {
                    $this->people[$currentId] = [
                        'id' => $currentId,
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
                        'id' => $currentId,
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
                $this->parsePersonLine($currentId, $line);
            }

            if ($currentType === 'FAM') {
                $this->parseFamilyLine($currentId, $line);
            }
        }
    }

    private function parsePersonLine(string $id, string $line): void
    {
        if (preg_match('/^1 NAME (.+)$/', $line, $m)) {
            $this->people[$id]['name'] = trim($m[1]);
        }

        if (preg_match('/^1 SEX (.+)$/', $line, $m)) {
            $this->people[$id]['sex'] = trim($m[1]);
        }

        if (preg_match('/^1 OCCU (.+)$/', $line, $m)) {
            $this->people[$id]['occupation'] = trim($m[1]);
        }

        if (preg_match('/^1 NOTE (.+)$/', $line, $m)) {
            $this->people[$id]['note'] = trim($m[1]);
        }

        if (preg_match('/^2 FILE (https?:\/\/.+)$/', $line, $m)) {
            $this->people[$id]['photo'] = trim($m[1]);
        }

        static $event = null;

        if (preg_match('/^1 BIRT/', $line)) {
            $event = 'birth';
        } elseif (preg_match('/^1 DEAT/', $line)) {
            $event = 'death';
        } elseif (preg_match('/^1 [A-Z_]+/', $line)) {
            $event = null;
        }

        if ($event && preg_match('/^2 DATE (.+)$/', $line, $m)) {
            $this->people[$id][$event.'_date'] = $this->parseDate($m[1]);
        }

        if ($event && preg_match('/^2 PLAC (.+)$/', $line, $m)) {
            $this->people[$id][$event.'_place'] = trim($m[1]);
        }
    }

    private function parseFamilyLine(string $id, string $line): void
    {
        if (preg_match('/^1 HUSB @([^@]+)@/', $line, $m)) {
            $this->families[$id]['husb'] = $m[1];
        }

        if (preg_match('/^1 WIFE @([^@]+)@/', $line, $m)) {
            $this->families[$id]['wife'] = $m[1];
        }

        if (preg_match('/^1 CHIL @([^@]+)@/', $line, $m)) {
            $this->families[$id]['children'][] = $m[1];
        }

        static $event = null;

        if (preg_match('/^1 MARR/', $line)) {
            $event = 'marriage';
        } elseif (preg_match('/^1 DIV/', $line)) {
            $event = 'divorce';
        } elseif (preg_match('/^1 [A-Z_]+/', $line)) {
            $event = null;
        }

        if ($event === 'marriage' && preg_match('/^2 DATE (.+)$/', $line, $m)) {
            $this->families[$id]['marriage_date'] = $this->parseDate($m[1]);
        }

        if ($event === 'marriage' && preg_match('/^2 PLAC (.+)$/', $line, $m)) {
            $this->families[$id]['marriage_place'] = trim($m[1]);
        }

        if ($event === 'divorce' && preg_match('/^2 DATE (.+)$/', $line, $m)) {
            $this->families[$id]['divorce_date'] = $this->parseDate($m[1]);
        }
    }

    private function importPeople(): void
    {
        foreach ($this->people as $gedcomId => $person) {
            [$firstName, $middleName, $lastName] = $this->splitName($person['name']);

            $photoPath = null;

            if ($this->option('photos') && $person['photo']) {
                $photoPath = $this->downloadPhoto($person['photo'], $gedcomId);
            }

            'first_name' => $this->cleanText($firstName ?: 'Без имени'),
            'middle_name' => $this->cleanText($middleName),
            'last_name' => $this->cleanText($lastName ?: 'Без фамилии'),
            'maiden_name' => null,
            'gender' => ...,
            'birth_place' => $this->cleanText($person['birth_place']),
            'current_city' => null,
            'occupation' => $this->cleanText($person['occupation']),
            'bio' => $this->cleanText($person['note']),
            'photo_path' => $this->cleanText($photoPath),

            $id = DB::table('people')->insertGetId([
                'first_name' => $firstName ?: 'Без имени',
                'middle_name' => $middleName,
                'last_name' => $lastName ?: 'Без фамилии',
                'maiden_name' => null,
                'gender' => match ($person['sex']) {
                    'M' => 'male',
                    'F' => 'female',
                    default => 'unknown',
                },
                'birth_date' => $person['birth_date'],
                'death_date' => $person['death_date'],
                'birth_place' => $person['birth_place'],
                'current_city' => null,
                'occupation' => $person['occupation'],
                'bio' => $person['note'],
                'photo_path' => $photoPath,
                'is_published' => 1,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->idMap[$gedcomId] = $id;
        }
    }

    private function importFamilies(): void
    {
        foreach ($this->families as $family) {
            $parents = array_filter([
                $family['husb'] ? ($this->idMap[$family['husb']] ?? null) : null,
                $family['wife'] ? ($this->idMap[$family['wife']] ?? null) : null,
            ]);

            if (count($parents) === 2) {

 

                DB::table('partnerships')->insert([
                    'partner_one_id' => array_values($parents)[0],
                    'partner_two_id' => array_values($parents)[1],
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
        $name = trim(str_replace('/', '', (string) $name));

        if ($name === '') {
            return [null, null, null];
        }

        $parts = preg_split('/\s+/', $name);

        $lastName = array_pop($parts);
        $firstName = array_shift($parts);
        $middleName = $parts ? implode(' ', $parts) : null;

        return [$firstName, $middleName, $lastName];
    }

    private function parseDate(?string $date): ?string
    {
        if (! $date) {
            return null;
        }

        $date = strtoupper(trim($date));

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
            return sprintf('%04d-%02d-%02d', $m[3], $months[$m[2]] ?? 1, $m[1]);
        }

        if (preg_match('/^([A-Z]{3}) (\d{4})$/', $date, $m)) {
            return sprintf('%04d-%02d-01', $m[2], $months[$m[1]] ?? 1);
        }

        if (preg_match('/^(\d{4})$/', $date, $m)) {
            return "{$m[1]}-01-01";
        }

        return null;
    }

    private function downloadPhoto(string $url, string $gedcomId): ?string
    {
        try {
            $response = Http::timeout(20)->get($url);

            if (! $response->successful()) {
                return null;
            }

            $path = 'people/photos/'.Str::slug($gedcomId).'.jpg';

            Storage::disk('public')->put($path, $response->body());

            return $path;
        } catch (\Throwable) {
            return null;
        }
    }
    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // убрать BOM
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

        // убрать невалидные UTF-8 байты
        $value = iconv('UTF-8', 'UTF-8//IGNORE', $value);

        return trim($value);
    }
}