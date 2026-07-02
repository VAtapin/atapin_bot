<?php

namespace App\Services;

use App\Models\Person;
use App\Models\TreeImport;
use App\Support\CurrentTree;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class TreeImportService
{
    public function process(TreeImport $import): TreeImport
    {
        $import->update(['status' => 'processing', 'error' => null]);
        app(CurrentTree::class)->set($import->tree);

        try {
            $path = Storage::disk('local')->path($import->path);
            $statistics = match ($import->format) {
                'gedcom' => $this->gedcom($import, $path),
                'csv' => $this->csv($import, $path),
                'gramps' => $this->gramps($import, $path),
                default => throw new RuntimeException('Неизвестный формат импорта.'),
            };
            $import->update([
                'status' => 'completed',
                'statistics' => $statistics,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $import->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);
        }

        return $import->fresh();
    }

    private function gedcom(TreeImport $import, string $path): array
    {
        $arguments = ['file' => $path, '--tree' => $import->tree_id];
        if ($import->replace_existing) {
            $arguments['--fresh'] = true;
        }
        if ($import->download_photos) {
            $arguments['--photos'] = true;
        }

        $exitCode = Artisan::call('gedcom:import', $arguments);
        if ($exitCode !== 0) {
            throw new RuntimeException(Artisan::output() ?: 'Ошибка импорта GEDCOM.');
        }

        return ['output' => Artisan::output()];
    }

    private function csv(TreeImport $import, string $path): array
    {
        if ($import->replace_existing) {
            Person::query()->delete();
        }

        $handle = fopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Не удалось открыть CSV.');
        }
        $headers = array_map(
            fn (string $header): string => mb_strtolower(trim($header)),
            fgetcsv($handle, separator: ',') ?: [],
        );
        $count = 0;

        DB::transaction(function () use ($handle, $headers, &$count): void {
            while (($row = fgetcsv($handle, separator: ',')) !== false) {
                $data = array_combine($headers, array_pad($row, count($headers), null));
                if (! $data || blank($data['first_name'] ?? null)) {
                    continue;
                }
                Person::query()->create([
                    'first_name' => $data['first_name'],
                    'middle_name' => $data['middle_name'] ?? null,
                    'last_name' => $data['last_name'] ?? '',
                    'maiden_name' => $data['maiden_name'] ?? null,
                    'gender' => $data['gender'] ?? 'unknown',
                    'birth_date' => $data['birth_date'] ?? null,
                    'death_date' => $data['death_date'] ?? null,
                    'birth_place' => $data['birth_place'] ?? null,
                    'current_city' => $data['current_city'] ?? null,
                    'occupation' => $data['occupation'] ?? null,
                    'bio' => $data['bio'] ?? null,
                    'is_published' => true,
                ]);
                $count++;
            }
        });
        fclose($handle);

        return ['people' => $count];
    }

    private function gramps(TreeImport $import, string $path): array
    {
        $xml = simplexml_load_file($path);
        if (! $xml) {
            throw new RuntimeException('Некорректный файл Gramps XML.');
        }
        if ($import->replace_existing) {
            Person::query()->delete();
        }

        $count = 0;
        foreach ($xml->people->person ?? [] as $node) {
            $name = $node->name[0] ?? null;
            $firstName = trim((string) ($name->first ?? ''));
            $lastName = trim((string) ($name->surname ?? ''));
            if ($firstName === '' && $lastName === '') {
                continue;
            }
            Person::query()->create([
                'first_name' => $firstName ?: '?',
                'last_name' => $lastName,
                'gender' => match (mb_strtoupper((string) ($node->gender ?? ''))) {
                    'M' => 'male',
                    'F' => 'female',
                    default => 'unknown',
                },
                'gedcom_id' => (string) ($node['id'] ?? $node['handle'] ?? ''),
                'is_published' => true,
            ]);
            $count++;
        }

        return ['people' => $count];
    }
}
