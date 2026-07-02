<?php

namespace App\Support;

class SafeReturnUrl
{
    public static function path(?string $value): ?string
    {
        $value = trim((string) $value);

        if (
            $value === ''
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, "\r")
            || str_contains($value, "\n")
        ) {
            return null;
        }

        return $value;
    }
}
