<?php

namespace App\Models;

use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class FamilyTree extends Model implements HasCurrentTenantLabel, HasName
{
    use SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'start_person_id',
        'plan_id',
        'name',
        'slug',
        'subtitle',
        'status',
        'locale',
        'timezone',
        'primary_domain',
        'domain_status',
        'domain_verification_token',
        'domain_verified_at',
        'domain_ssl_status',
        'domain_checked_at',
        'domain_last_error',
        'accent_color',
        'crest_path',
        'custom_bot_token',
        'custom_bot_username',
        'custom_bot_webhook_secret',
        'custom_bot_verified_at',
        'settings',
        'storage_used_bytes',
        'trial_ends_at',
        'last_activity_at',
        'deletion_scheduled_at',
        'deletion_requested_by_user_id',
        'deletion_reason',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'storage_used_bytes' => 'integer',
            'trial_ends_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
            'custom_bot_token' => 'encrypted',
            'custom_bot_webhook_secret' => 'encrypted',
            'custom_bot_verified_at' => 'datetime',
            'domain_verified_at' => 'datetime',
            'domain_checked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return 'Текущее дерево';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function startPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'start_person_id');
    }

    public function deletionRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deletion_requested_by_user_id');
    }

    public function getCrestUrlAttribute(): ?string
    {
        return $this->crest_path
            ? Storage::disk('public')->url($this->crest_path)
            : null;
    }

    public function isDeletionScheduled(): bool
    {
        return $this->status === 'deleting' && $this->deletion_scheduled_at !== null;
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

    public function parentChildren(): HasMany
    {
        return $this->hasMany(ParentChild::class, 'tree_id');
    }

    public function partnerships(): HasMany
    {
        return $this->hasMany(Partnership::class, 'tree_id');
    }

    public function familyEvents(): HasMany
    {
        return $this->hasMany(FamilyEvent::class, 'tree_id');
    }

    public function photoAlbums(): HasMany
    {
        return $this->hasMany(PhotoAlbum::class, 'tree_id');
    }

    public function personPhotos(): HasMany
    {
        return $this->hasMany(PersonPhoto::class, 'tree_id');
    }

    public function settings(): HasMany
    {
        return $this->hasMany(Setting::class, 'tree_id');
    }

    public function telegramGroups(): HasMany
    {
        return $this->hasMany(TelegramGroup::class, 'tree_id');
    }

    public function dataIssues(): HasMany
    {
        return $this->hasMany(DataIssue::class, 'tree_id');
    }

    public function changeLogs(): HasMany
    {
        return $this->hasMany(ChangeLog::class, 'tree_id');
    }

    public function treeImports(): HasMany
    {
        return $this->hasMany(TreeImport::class, 'tree_id');
    }

    public function treeInvitations(): HasMany
    {
        return $this->hasMany(TreeInvitation::class, 'tree_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'tree_id');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(TreeBackup::class, 'tree_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'tree_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
