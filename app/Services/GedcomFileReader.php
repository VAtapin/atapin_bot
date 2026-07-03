<?php

namespace App\Services;

use RuntimeException;

class GedcomFileReader
{
    /**
     * @return array{text: string, encoding: string}
     */
    public function read(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Файл GEDCOM пуст или недоступен.');
        }

        $encoding = $this->detectEncoding($contents);
        $contents = $this->stripBom($contents, $encoding);

        if ($encoding !== 'UTF-8') {
            $converted = @mb_convert_encoding($contents, 'UTF-8', $encoding);
            if (! is_string($converted) || $converted === '') {
                throw new RuntimeException("Не удалось преобразовать GEDCOM из {$encoding} в UTF-8.");
            }
            $contents = $converted;
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/u', '', $contents) ?? $contents;
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        $contents = ltrim($contents, "\xEF\xBB\xBF \t\n");

        return ['text' => $contents, 'encoding' => $encoding];
    }

    private function detectEncoding(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return 'UTF-8';
        }
        if (str_starts_with($contents, "\xFF\xFE")) {
            return 'UTF-16LE';
        }
        if (str_starts_with($contents, "\xFE\xFF")) {
            return 'UTF-16BE';
        }

        $sample = substr($contents, 0, 8192);
        if (str_contains($sample, "\0")) {
            $evenNulls = 0;
            $oddNulls = 0;
            for ($index = 0, $length = strlen($sample); $index < $length; $index++) {
                if ($sample[$index] !== "\0") {
                    continue;
                }
                $index % 2 === 0 ? $evenNulls++ : $oddNulls++;
            }

            if ($oddNulls > $evenNulls * 3) {
                return 'UTF-16LE';
            }
            if ($evenNulls > $oddNulls * 3) {
                return 'UTF-16BE';
            }
            throw new RuntimeException('GEDCOM содержит бинарные данные и не является текстовым файлом.');
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return 'UTF-8';
        }

        $declared = null;
        if (preg_match('/(?:^|\R)1\s+CHAR\s+([^\r\n]+)/i', $sample, $match)) {
            $declared = mb_strtoupper(trim($match[1]));
        }

        return match ($declared) {
            'UTF-8', 'UNICODE' => 'UTF-8',
            'WINDOWS-1251', 'CP1251' => 'Windows-1251',
            'ANSI', 'WINDOWS' => 'Windows-1252',
            'ASCII', 'ANSEL' => 'Windows-1252',
            default => mb_detect_encoding($sample, ['Windows-1251', 'Windows-1252', 'ISO-8859-1'], true)
                ?: 'Windows-1251',
        };
    }

    private function stripBom(string $contents, string $encoding): string
    {
        return match ($encoding) {
            'UTF-8' => str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents,
            'UTF-16LE', 'UTF-16BE' => str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")
                ? substr($contents, 2)
                : $contents,
            default => $contents,
        };
    }
}
