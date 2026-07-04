<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomeSectionItemTranslation extends Model
{
    protected $fillable = [
        'locale',
        'title',
        'text',
        'image_alt',
        'button_label',
        'button_action',
        'button_url',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(HomeSectionItem::class, 'home_section_item_id');
    }
}
