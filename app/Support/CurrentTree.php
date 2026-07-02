<?php

namespace App\Support;

use App\Models\FamilyTree;
use App\Models\User;

class CurrentTree
{
    private ?FamilyTree $tree = null;

    public function set(?FamilyTree $tree): void
    {
        $this->tree = $tree;
    }

    public function clear(): void
    {
        $this->tree = null;
    }

    public function get(): ?FamilyTree
    {
        return $this->tree;
    }

    public function id(): ?int
    {
        return $this->tree?->id;
    }

    public function resolveDefault(?User $user = null): ?FamilyTree
    {
        if ($this->tree) {
            return $this->tree;
        }

        if (! $user || $user->is_super_admin) {
            return null;
        }

        if ($user->last_tree_id) {
            $lastTree = $user->trees()
                ->whereKey($user->last_tree_id)
                ->wherePivot('status', 'approved')
                ->where('family_trees.status', 'active')
                ->first();

            if ($lastTree) {
                return $this->tree = $lastTree;
            }
        }

        return $this->tree = $user->trees()
            ->wherePivot('status', 'approved')
            ->where('family_trees.status', 'active')
            ->orderBy('family_trees.id')
            ->first();
    }
}
