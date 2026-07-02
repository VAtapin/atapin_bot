<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreeImport extends Model
{
    protected $fillable = [
        'tree_id',
        'created_by_user_id',
        'format',
        'status',
        'path',
        'original_name',
        'replace_existing',
        'download_photos',
        'statistics',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'replace_existing' => 'boolean',
            'download_photos' => 'boolean',
            'statistics' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }
}
