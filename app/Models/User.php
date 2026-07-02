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

#[Fillable(['name', 'email', 'login', 'password', 'is_active', 'is_super_admin', 'two_factor_enabled', 'last_tree_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
            'two_factor_enabled' => 'boolean',
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
                ->where('status', 'active')
                ->orderBy('name')
                ->get();
        }

        return $this->trees()
            ->wherePivot('status', 'approved')
            ->wherePivotIn('role', ['owner', 'moderator'])
            ->where('family_trees.status', 'active')
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
