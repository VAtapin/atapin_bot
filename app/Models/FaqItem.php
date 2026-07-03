<?php

namespace App\Models;

use App\Services\SafeHtml;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqItem extends Model
{
    protected $fillable = [
        'faq_category_id',
        'question',
        'answer',
        'keywords',
        'sort_order',
        'is_published',
    ];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'faq_category_id');
    }

    public function setAnswerAttribute(?string $value): void
    {
        $this->attributes['answer'] = app(SafeHtml::class)->clean($value);
    }
}
