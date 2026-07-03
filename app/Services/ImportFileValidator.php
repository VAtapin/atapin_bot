<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class ImportFileValidator
{
    public const EXTENSIONS = [
        'gedcom' => ['ged', 'gedcom'],
        'gramps' => ['gramps', 'xml'],
        'csv' => ['csv'],
    ];

    public function validate(string $format, string $path, ?string $originalName = null): void
    {
        $extensions = self::EXTENSIONS[$format] ?? [];
        $extension = mb_strtolower(pathinfo($originalName ?: $path, PATHINFO_EXTENSION));

        if (! in_array($extension, $extensions, true)) {
            throw new RuntimeException(
                'Для выбранного формата разрешены только файлы: .'.implode(', .', $extensions),
            );
        }

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Файл пуст или недоступен.');
        }

        if (filesize($path) > 100 * 1024 * 1024) {
            throw new RuntimeException('Размер файла превышает 100 МБ.');
        }

        $sample = file_get_contents($path, false, null, 0, 262144);
        if ($sample === false) {
            throw new RuntimeException('Не удалось прочитать файл.');
        }

        if ($this->hasExecutableSignature($sample)) {
            throw new RuntimeException('Обнаружено исполняемое содержимое.');
        }

        if ($format === 'gedcom') {
            $decoded = app(GedcomFileReader::class)->read($path);
            $this->validateGedcom($decoded['text']);

            return;
        }

        if ($format === 'gramps' && $extension === 'gramps') {
            $this->validateCompressedGramps($path, $sample);

            return;
        }

        if ($this->isBinary($sample)) {
            throw new RuntimeException('Бинарные и исполняемые файлы запрещены.');
        }

        match ($format) {
            'gramps' => $this->validateXml($sample),
            'csv' => $this->validateCsv($sample),
            default => throw new RuntimeException('Неизвестный формат импорта.'),
        };
    }

    public function gedcomEncoding(string $path): string
    {
        return app(GedcomFileReader::class)->read($path)['encoding'];
    }

    public function validateUpload(string $format, mixed $value): void
    {
        if (! $value instanceof UploadedFile) {
            throw new RuntimeException('Не удалось прочитать загруженный файл.');
        }

        $this->validate($format, $value->getRealPath(), $value->getClientOriginalName());
    }

    private function isBinary(string $sample): bool
    {
        if (str_contains($sample, "\0")) {
            return true;
        }

        $length = max(strlen($sample), 1);
        $controlCharacters = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1F]/', $sample);

        return ($controlCharacters / $length) > 0.01;
    }

    private function hasExecutableSignature(string $sample): bool
    {
        return str_starts_with($sample, 'MZ')
            || str_starts_with($sample, "\x7FELF")
            || str_starts_with($sample, '#!');
    }

    private function validateGedcom(string $sample): void
    {
        if (! preg_match('/(?:^|\R)0\s+HEAD(?:\s|$)/i', $sample)) {
            throw new RuntimeException('Файл не содержит заголовок GEDCOM «0 HEAD».');
        }

        if (! preg_match('/(?:^|\R)0\s+@[^@]+@\s+(?:INDI|FAM)(?:\s|$)/i', $sample)) {
            throw new RuntimeException('Файл не содержит записей людей или семей GEDCOM.');
        }
    }

    private function validateXml(string $sample): void
    {
        if (stripos($sample, '<!DOCTYPE') !== false || stripos($sample, '<!ENTITY') !== false) {
            throw new RuntimeException('DTD и внешние XML-сущности запрещены.');
        }

        if (! preg_match('/<\s*(?:\?xml\b|database\b|gramps\b)/i', ltrim($sample))) {
            throw new RuntimeException('Файл не похож на Gramps XML.');
        }
    }

    private function validateCompressedGramps(string $path, string $sample): void
    {
        if (! str_starts_with($sample, "\x1F\x8B")) {
            throw new RuntimeException('Файл .gramps должен быть архивом Gramps в формате gzip.');
        }

        $handle = @gzopen($path, 'rb');
        if (! $handle) {
            throw new RuntimeException('Не удалось открыть архив Gramps.');
        }

        $xmlSample = '';
        while (! gzeof($handle) && strlen($xmlSample) < 262144) {
            $chunk = gzread($handle, 32768);
            if ($chunk === false) {
                gzclose($handle);
                throw new RuntimeException('Архив Gramps повреждён.');
            }
            $xmlSample .= $chunk;
        }
        gzclose($handle);

        if ($xmlSample === '' || $this->isBinary($xmlSample)) {
            throw new RuntimeException('Архив Gramps не содержит безопасный XML.');
        }

        $this->validateXml($xmlSample);
    }

    private function validateCsv(string $sample): void
    {
        $firstLine = strtok($sample, "\r\n") ?: '';

        if (! str_contains($firstLine, ',') && ! str_contains($firstLine, ';')) {
            throw new RuntimeException('CSV должен содержать строку заголовков с разделителями.');
        }
    }
}
