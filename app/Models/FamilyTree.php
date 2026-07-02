<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FamilyTree extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'plan_id',
        'name',
        'slug',
        'subtitle',
        'status',
        'locale',
        'timezone',
        'primary_domain',
        'accent_color',
        'settings',
        'storage_used_bytes',
        'trial_ends_at',
        'last_activity_at',
        'deletion_scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'storage_used_bytes' => 'integer',
            'trial_ends_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TreeMembership::class, 'tree_id');
    }

    public function people(): HasMany
    {
        return $this->hasMany(Person::class, 'tree_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'tree_id');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(TreeBackup::class, 'tree_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
