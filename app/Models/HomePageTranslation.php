<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HomePageTranslation extends Model
{
    protected $fillable = [
        'locale',
        'meta_title',
        'meta_description',
        'og_image_path',
        'og_image_alt',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(HomePage::class, 'home_page_id');
    }
}
