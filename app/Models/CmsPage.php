<?php

namespace App\Models;

use App\Services\SafeHtml;
use Illuminate\Database\Eloquent\Model;

class CmsPage extends Model
{
    protected $fillable = [
        'locale',
        'slug',
        'title',
        'meta_title',
        'meta_description',
        'og_image_path',
        'content',
        'status',
        'is_published',
        'published_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = app(SafeHtml::class)->clean($value);
    }

    public function versions()
    {
        return $this->hasMany(CmsPageVersion::class)->latest();
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
