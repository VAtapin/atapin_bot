<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomePage extends Model
{
    protected $fillable = ['status', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(HomePageTranslation::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(HomeSection::class)->orderBy('sort_order')->orderBy('id');
    }

    public function translation(?string $locale = null): ?HomePageTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', 'ru')
            ?? $this->translations->first();
    }
}
