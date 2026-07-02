<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
