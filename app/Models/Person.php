<?php

namespace App\Models;

use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'first_name',
        'gedcom_id',
        'middle_name',
        'last_name',
        'maiden_name',
        'married_name',
        'gender',
        'birth_date',
        'death_date',
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
            'is_published' => 'boolean',
        ];
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
        if (! $this->birth_date && ! $this->death_date) {
            return null;
        }

        return ($this->birth_date?->format('Y') ?? '?')
            .' — '
            .($this->death_date?->format('Y') ?? 'н.в.');
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birth_date?->diffInYears($this->death_date ?? now());
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
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
}
