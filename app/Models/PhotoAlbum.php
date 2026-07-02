<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PhotoAlbum extends Model
{
    use BelongsToTree;
    use RecordsChanges;

    protected $fillable = [
        'tree_id',
        'person_id',
        'created_by_telegram_user_id',
        'title',
        'description',
        'sort_order',
    ];

    protected static function booted(): void
    {
        static::saving(function (PhotoAlbum $album): void {
            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($album->person_id)
                ->value('tree_id');

            if (! $personTreeId || ($album->tree_id && (int) $album->tree_id !== (int) $personTreeId)) {
                throw ValidationException::withMessages([
                    'person_id' => 'Альбом и человек должны находиться в одном дереве.',
                ]);
            }

            $album->tree_id = $personTreeId;
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'created_by_telegram_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PersonPhoto::class);
    }
}
