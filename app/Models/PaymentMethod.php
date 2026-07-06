<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $fillable = [
        'code',
        'name',
        'provider',
        'region',
        'currency',
        'is_active',
        'test_mode',
        'credentials',
        'webhook_secret',
        'instructions',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'is_active' => 'boolean',
            'test_mode' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActiveFor(Builder $query, string $region, string $currency): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('region', $region)
            ->where('currency', strtoupper($currency))
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }
}
