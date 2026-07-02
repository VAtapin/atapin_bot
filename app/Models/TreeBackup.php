<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TreeBackup extends Model
{
    protected $fillable = [
        'tree_id',
        'created_by_user_id',
        'type',
        'status',
        'path',
        'size',
        'statistics',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'statistics' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (TreeBackup $backup): void {
            if ($backup->path) {
                Storage::disk('local')->deleteDirectory(dirname($backup->path));
            }
        });
    }

    public function tree(): BelongsTo
    {
        return $this->belongsTo(FamilyTree::class, 'tree_id');
    }
}
