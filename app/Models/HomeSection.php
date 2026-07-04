<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HomeSection extends Model
{
    protected $fillable = [
        'home_page_id',
        'type',
        'image_path',
        'image_position',
        'settings',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(HomePage::class, 'home_page_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(HomeSectionTranslation::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(HomeSectionItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function translation(?string $locale = null): ?HomeSectionTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->firstWhere('locale', 'ru')
            ?? $this->translations->first();
    }
}
