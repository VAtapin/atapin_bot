<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'group', 'value', 'type', 'is_secret', 'label', 'description', 'sort_order'];

    protected function casts(): array
    {
        return ['is_secret' => 'boolean', 'sort_order' => 'integer'];
    }

    public function getValueAttribute(?string $value): ?string
    {
        if (! $this->is_secret || blank($value) || ! str_starts_with($value, 'enc:')) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, 4));
        } catch (Throwable) {
            return null;
        }
    }

    public function setValueAttribute(mixed $value): void
    {
        if ($this->is_secret && filled($value) && ! str_starts_with((string) $value, 'enc:')) {
            $this->attributes['value'] = 'enc:'.Crypt::encryptString((string) $value);

            return;
        }

        $this->attributes['value'] = $value;
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        $setting = static::query()->where('key', $key)->first();
        if (! $setting) {
            return $default;
        }

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOL),
            'integer' => (int) $setting->value,
            'json' => json_decode($setting->value ?? 'null', true),
            default => $setting->value,
        };
    }
}
