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

    public function priceFor(?string $region = null, ?string $currency = null): ?PlanPrice
    {
        $region = $region ?: 'eu';
        $currency = strtoupper($currency ?: ($region === 'ru' ? 'RUB' : 'EUR'));

        $loadedPrices = $this->relationLoaded('prices') ? $this->prices : null;
        $price = $loadedPrices
            ? $loadedPrices->first(fn (PlanPrice $price): bool => $price->is_active
                && $price->region === $region
                && strtoupper($price->currency) === $currency)
            : $this->prices()
                ->where('is_active', true)
                ->where('region', $region)
                ->where('currency', $currency)
                ->first();

        return $price ?: ($loadedPrices
            ? $loadedPrices->first(fn (PlanPrice $price): bool => $price->is_active && $price->region === $region)
            : $this->prices()
                ->where('is_active', true)
                ->where('region', $region)
                ->first());
    }

    public function priceAmountFor(?string $region = null, ?string $currency = null): float
    {
        return (float) ($this->priceFor($region, $currency)?->price_monthly ?? $this->price_monthly);
    }

    public function currencyFor(?string $region = null, ?string $currency = null): string
    {
        return strtoupper((string) ($this->priceFor($region, $currency)?->currency ?? $this->currency));
    }

    public function providerPriceReferenceFor(?string $region = null, ?string $currency = null): ?string
    {
        $price = $this->priceFor($region, $currency);

        return $price
            ? $price->provider_price_reference
            : $this->provider_price_reference;
    }

    public function isFreeFor(?string $region = null, ?string $currency = null): bool
    {
        return $this->priceAmountFor($region, $currency) <= 0.0;
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

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }
}
