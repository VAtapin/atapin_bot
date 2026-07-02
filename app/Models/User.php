<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'is_active', 'is_super_admin', 'two_factor_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
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
        return $this->is_active
            && (
                $this->is_super_admin
                || $this->memberships()
                    ->where('status', 'approved')
                    ->whereIn('role', ['owner', 'moderator'])
                    ->exists()
            );
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
}
