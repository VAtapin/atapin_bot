<?php

namespace App\Rules;

use App\Services\ImportFileValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class SafeImportFile implements ValidationRule
{
    public function __construct(private readonly string $format) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            app(ImportFileValidator::class)->validateUpload($this->format, $value);
        } catch (Throwable $exception) {
            $fail($exception->getMessage());
        }
    }
}
