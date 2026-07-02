<?php

namespace App\Models;

use App\Support\CurrentTree;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['tree_id', 'key', 'value', 'type', 'label', 'description'];

    public static function value(string $key, mixed $default = null): mixed
    {
        $treeId = app(CurrentTree::class)->id();
        $setting = static::query()
            ->where('key', $key)
            ->when($treeId, fn ($query) => $query->where(
                fn ($query) => $query->where('tree_id', $treeId)->orWhereNull('tree_id'),
            ))
            ->orderByRaw('tree_id is null')
            ->first();

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
