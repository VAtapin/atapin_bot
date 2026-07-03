<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use App\Services\TreeStorageService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

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
        'thumbnail_path',
        'source_url',
        'title',
        'description',
        'taken_at',
        'is_primary',
        'sort_order',
        'gedcom_data',
        'file_size',
        'thumbnail_file_size',
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
            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($photo->person_id)
                ->value('tree_id');

            if (! $personTreeId || ($photo->tree_id && (int) $photo->tree_id !== (int) $personTreeId)) {
                throw ValidationException::withMessages([
                    'person_id' => 'Фотография и человек должны находиться в одном дереве.',
                ]);
            }

            if ($photo->photo_album_id) {
                $albumTreeId = PhotoAlbum::withoutGlobalScope('family_tree')
                    ->whereKey($photo->photo_album_id)
                    ->value('tree_id');

                if ((int) $albumTreeId !== (int) $personTreeId) {
                    throw ValidationException::withMessages([
                        'photo_album_id' => 'Альбом находится в другом дереве.',
                    ]);
                }
            }

            $photo->tree_id = $personTreeId;

            if (
                ! app()->runningInConsole()
                && $photo->path
                && $photo->isDirty('path')
                && Storage::disk('public')->exists($photo->path)
            ) {
                $newSize = Storage::disk('public')->size($photo->path);
                $additionalBytes = max(0, $newSize - (int) $photo->getOriginal('file_size', 0));
                try {
                    app(TreeStorageService::class)->ensureCanStore(
                        FamilyTree::query()->findOrFail($personTreeId),
                        $additionalBytes,
                    );
                } catch (\Throwable $exception) {
                    if ($photo->path !== $photo->getOriginal('path')) {
                        Storage::disk('public')->delete($photo->path);
                    }

                    throw $exception;
                }
                $photo->file_size = $newSize;
            }

            if ($photo->is_primary && $photo->person_id) {
                static::query()
                    ->where('person_id', $photo->person_id)
                    ->when($photo->exists, fn ($query) => $query->whereKeyNot($photo->getKey()))
                    ->update(['is_primary' => false]);
            }
        });

        static::updated(function (PersonPhoto $photo): void {
            $oldPath = $photo->getOriginal('path');
            if ($photo->wasChanged('path') && $oldPath && $oldPath !== $photo->path) {
                Storage::disk('public')->delete($oldPath);
            }
            if (! app()->runningInConsole()) {
                app(TreeStorageService::class)->recalculate($photo->tree);
            }
        });

        static::created(function (PersonPhoto $photo): void {
            if (! app()->runningInConsole()) {
                app(TreeStorageService::class)->recalculate($photo->tree);
            }
        });

        static::deleting(function (PersonPhoto $photo): void {
            if ($photo->path) {
                Storage::disk('public')->delete($photo->path);
            }
        });

        static::deleted(function (PersonPhoto $photo): void {
            if (! app()->runningInConsole()) {
                app(TreeStorageService::class)->recalculate($photo->tree);
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
        return $this->path && Storage::disk('public')->exists($this->path)
            ? URL::temporarySignedRoute(
                'media.photo',
                now()->startOfHour()->addHours(6),
                ['photo' => $this->id],
            )
            : $this->source_url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->thumbnail_path && Storage::disk('public')->exists($this->thumbnail_path)) {
            return URL::temporarySignedRoute(
                'media.photo-thumbnail',
                now()->startOfMinute()->addHours(6),
                ['photo' => $this->id],
            );
        }

        return $this->url;
    }
}
