<?php

namespace App\Filament\Components;

use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Contracts\Support\Arrayable;

class ImportFileUpload extends FileUpload
{
    /**
     * Ограничивает системный диалог расширениями, не добавляя ненадёжную
     * MIME-проверку Filament. Серверная проверка выполняется отдельным правилом.
     *
     * @param  array<string>|Arrayable|Closure  $extensions
     */
    public function acceptedExtensions(array|Arrayable|Closure $extensions): static
    {
        $this->acceptedFileTypes = $extensions;

        return $this;
    }
}
