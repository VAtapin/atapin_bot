<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'email', 'login', 'password', 'is_active', 'is_super_admin', 'super_admin_assigned_by_user_id', 'super_admin_assigned_at', 'two_factor_enabled', 'two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_used_counter', 'last_tree_id', 'merged_into_user_id', 'merged_at'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->is_super_admin) {
                $user->two_factor_enabled = true;
            }
            if (
                $user->exists
                && $user->getOriginal('is_super_admin')
                && $user->getOriginal('is_active')
                && (
                    ($user->isDirty('is_super_admin') && ! $user->is_super_admin)
                    || ($user->isDirty('is_active') && ! $user->is_active)
                )
                && static::query()
                    ->where('is_super_admin', true)
                    ->where('is_active', true)
                    ->whereKeyNot($user->id)
                    ->doesntExist()
            ) {
                throw ValidationException::withMessages([
                    'is_super_admin' => 'Нельзя отключить последнего активного суперадминистратора.',
                ]);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'super_admin_assigned_at' => 'datetime',
            'two_factor_enabled' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_counter' => 'integer',
            'merged_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($panel->getId() === 'admin') {
            return $this->is_super_admin;
        }

        if ($panel->getId() === 'tree') {
            return $this->is_super_admin || $this->memberships()
                ->where('status', 'approved')
                ->whereIn('role', ['owner', 'moderator'])
                ->exists();
        }

        return false;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TreeMembership::class);
    }

    public function trees(): BelongsToMany
    {
        return $this->belongsToMany(FamilyTree::class, 'tree_memberships', 'user_id', 'tree_id')
            ->withPivot(['role', 'status', 'person_id'])
            ->withTimestamps();
    }

    public function externalIdentities(): HasMany
    {
        return $this->hasMany(ExternalIdentity::class);
    }

    public function canManageTree(FamilyTree $tree): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->memberships()
            ->where('tree_id', $tree->id)
            ->where('status', 'approved')
            ->whereIn('role', ['owner', 'moderator'])
            ->exists();
    }

    public function approvedMembershipFor(FamilyTree $tree): ?TreeMembership
    {
        return $this->memberships()
            ->where('tree_id', $tree->id)
            ->where('status', 'approved')
            ->first();
    }

    public function roleInTree(FamilyTree $tree): ?string
    {
        if ($this->is_super_admin) {
            return 'super_admin';
        }

        return $this->approvedMembershipFor($tree)?->role;
    }

    public function ownsTree(FamilyTree $tree): bool
    {
        return $this->is_super_admin || $this->roleInTree($tree) === 'owner';
    }

    public function moderatesTree(FamilyTree $tree): bool
    {
        return in_array($this->roleInTree($tree), ['super_admin', 'owner', 'moderator'], true);
    }

    public function getTenants(Panel $panel): Collection
    {
        if ($panel->getId() !== 'tree') {
            return collect();
        }

        if ($this->is_super_admin) {
            return FamilyTree::query()
                ->whereIn('status', ['active', 'deleting'])
                ->orderBy('name')
                ->get();
        }

        return $this->trees()
            ->wherePivot('status', 'approved')
            ->wherePivotIn('role', ['owner', 'moderator'])
            ->whereIn('family_trees.status', ['active', 'deleting'])
            ->orderBy('family_trees.name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof FamilyTree
            && ($this->is_super_admin || $this->memberships()
                ->where('tree_id', $tenant->getKey())
                ->where('status', 'approved')
                ->whereIn('role', ['owner', 'moderator'])
                ->exists());
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $tenants = $this->getTenants($panel);

        return $tenants->firstWhere('id', $this->last_tree_id) ?? $tenants->first();
    }
}
