<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomeSectionItem extends Model
{
    protected $fillable = [
        'home_section_id',
        'icon',
        'image_path',
        'settings',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(HomeSection::class, 'home_section_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(HomeSectionItemTranslation::class);
    }

    public function translation(?string $locale = null): ?HomeSectionItemTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', 'ru')
            ?? $this->translations->first();
    }
}
