<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'storage_limit_bytes',
        'storage_limit_mb',
        'people_limit',
        'member_limit',
        'price_monthly',
        'currency',
        'provider_price_reference',
        'custom_bot',
        'custom_domain',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'custom_bot' => 'boolean',
            'custom_domain' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getStorageLimitMbAttribute(): int
    {
        return (int) round(((int) $this->storage_limit_bytes) / 1048576);
    }

    public function setStorageLimitMbAttribute(mixed $value): void
    {
        $this->attributes['storage_limit_bytes'] = max(1, (int) $value) * 1048576;
    }

    public function isFree(): bool
    {
        return (float) $this->price_monthly <= 0.0;
    }

    public function isPaid(): bool
    {
        return ! $this->isFree();
    }

    public function trees(): HasMany
    {
        return $this->hasMany(FamilyTree::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
