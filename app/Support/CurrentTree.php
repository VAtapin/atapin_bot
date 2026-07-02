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

        if ($user && ! $user->is_super_admin) {
            $this->tree = $user->trees()
                ->wherePivot('status', 'approved')
                ->where('family_trees.status', 'active')
                ->first();
        }

        return $this->tree ??= FamilyTree::query()
            ->where('status', 'active')
            ->oldest('id')
            ->first();
    }
}
