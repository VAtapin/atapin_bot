<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeletedTreeAudit extends Model
{
    protected $fillable = [
        'original_tree_id',
        'deleted_by_user_id',
        'name',
        'slug',
        'reason',
        'summary',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
