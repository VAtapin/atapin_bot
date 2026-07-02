<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class PersonPhoto extends Model
{
    use BelongsToTree;
    use RecordsChanges;

    protected $fillable = [
        'tree_id',
        'person_id',
        'photo_album_id',
        'uploaded_by_telegram_user_id',
        'gedcom_key',
        'path',
        'source_url',
        'title',
        'description',
        'taken_at',
        'is_primary',
        'sort_order',
        'gedcom_data',
        'file_size',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'date',
            'is_primary' => 'boolean',
            'gedcom_data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PersonPhoto $photo): void {
            if ($photo->is_primary && $photo->person_id) {
                static::query()
                    ->where('person_id', $photo->person_id)
                    ->when($photo->exists, fn ($query) => $query->whereKeyNot($photo->getKey()))
                    ->update(['is_primary' => false]);
            }
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(PhotoAlbum::class, 'photo_album_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'uploaded_by_telegram_user_id');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path
            ? URL::temporarySignedRoute('media.photo', now()->addMinutes(30), ['photo' => $this->id])
            : $this->source_url;
    }
}
