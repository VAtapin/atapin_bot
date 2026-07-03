<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTree;
use App\Models\Concerns\RecordsChanges;
use App\Support\CurrentTree;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class Person extends Model
{
    use BelongsToTree;

    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use RecordsChanges;
    use SoftDeletes;

    protected $hidden = ['password'];

    protected $fillable = [
        'first_name',
        'tree_id',
        'gedcom_id',
        'login',
        'password',
        'web_login_enabled',
        'middle_name',
        'last_name',
        'maiden_name',
        'married_name',
        'gender',
        'birth_date',
        'birth_date_precision',
        'death_date',
        'death_date_precision',
        'death_place',
        'burial_place',
        'birth_place',
        'current_city',
        'current_address',
        'occupation',
        'bio',
        'gedcom_data',
        'imported_at',
        'photo_path',
        'photo_thumbnail_path',
        'is_published',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
            'gedcom_data' => 'array',
            'imported_at' => 'datetime',
            'password' => 'hashed',
            'web_login_enabled' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Person $person): void {
            $tree = $person->tree_id
                ? FamilyTree::query()->with('plan')->find($person->tree_id)
                : app(CurrentTree::class)->resolveDefault();
            $limit = (int) ($tree?->plan?->people_limit ?? 0);

            if (
                $limit > 0
                && static::query()->withoutGlobalScope('family_tree')
                    ->where('tree_id', $tree->id)
                    ->count() >= $limit
            ) {
                throw ValidationException::withMessages([
                    'person' => 'Достигнут лимит людей для текущего тарифа.',
                ]);
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ])));
    }

    public function getLifeYearsAttribute(): ?string
    {
        if (! $this->death_date) {
            return null;
        }

        return ($this->birth_date?->format('Y') ?? '?')
            .' — '
            .$this->death_date->format('Y');
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->diffInYears($this->death_date ?? now());
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if ($this->photo_path && Storage::disk('public')->exists($this->photo_path)) {
            return URL::temporarySignedRoute(
                'media.person',
                now()->startOfHour()->addHours(6),
                ['person' => $this->id],
            );
        }

        $photo = $this->relationLoaded('photos')
            ? ($this->photos->firstWhere('is_primary', true) ?: $this->photos->first())
            : ($this->primaryPhoto()->first() ?: $this->photos()->first());

        return $photo?->url;
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if ($this->photo_thumbnail_path && Storage::disk('public')->exists($this->photo_thumbnail_path)) {
            return URL::temporarySignedRoute(
                'media.person-thumbnail',
                now()->startOfMinute()->addHours(6),
                ['person' => $this->id],
            );
        }

        $photo = $this->relationLoaded('photos')
            ? ($this->photos->firstWhere('is_primary', true) ?: $this->photos->first())
            : ($this->primaryPhoto()->first() ?: $this->photos()->first());

        return $photo?->thumbnail_url ?? $this->photo_url;
    }

    public function parentLinks(): HasMany
    {
        return $this->hasMany(ParentChild::class, 'child_id');
    }

    public function childLinks(): HasMany
    {
        return $this->hasMany(ParentChild::class, 'parent_id');
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            Person::class,
            'parent_children',
            'child_id',
            'parent_id',
        )->withPivot(['type', 'notes'])->withTimestamps();
    }

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            Person::class,
            'parent_children',
            'parent_id',
            'child_id',
        )->withPivot(['type', 'notes'])->withTimestamps();
    }

    public function telegramUsers(): HasMany
    {
        return $this->hasMany(TelegramUser::class);
    }

    public function partnershipsAsOne(): HasMany
    {
        return $this->hasMany(Partnership::class, 'partner_one_id');
    }

    public function partnershipsAsTwo(): HasMany
    {
        return $this->hasMany(Partnership::class, 'partner_two_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FamilyEvent::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(PersonPhoto::class)->orderBy('sort_order');
    }

    public function albums(): HasMany
    {
        return $this->hasMany(PhotoAlbum::class)->orderBy('sort_order');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TreeMembership::class);
    }

    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(PersonPhoto::class)->where('is_primary', true);
    }
}
