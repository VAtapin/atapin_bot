<?php

namespace App\Services;

class ColorContrast
{
    public function foreground(?string $hex): string
    {
        $hex = ltrim((string) $hex, '#');
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return '#ffffff';
        }

        [$red, $green, $blue] = array_map(
            fn (string $part): int => hexdec($part),
            str_split($hex, 2),
        );
        $luminance = (0.2126 * $red + 0.7152 * $green + 0.0722 * $blue) / 255;

        return $luminance > 0.58 ? '#1d1d1b' : '#ffffff';
    }
}
