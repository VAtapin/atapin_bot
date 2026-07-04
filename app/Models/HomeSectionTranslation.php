<?php

namespace App\Models;

use App\Services\SafeHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeSectionTranslation extends Model
{
    protected $fillable = [
        'locale',
        'eyebrow',
        'title',
        'lead',
        'content',
        'image_alt',
        'primary_label',
        'primary_action',
        'primary_url',
        'secondary_label',
        'secondary_action',
        'secondary_url',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(HomeSection::class, 'home_section_id');
    }

    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = filled($value) ? app(SafeHtml::class)->clean($value) : null;
    }
}
