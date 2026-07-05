<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\TreeMembership;
use App\Models\User;
use App\Support\CurrentTree;

class OwnerPersonService
{
    public function ensure(FamilyTree $tree, User $owner): TreeMembership
    {
        $currentTree = app(CurrentTree::class);
        $previousTree = $currentTree->get();
        $currentTree->set($tree);

        try {
            $person = $this->findUnlinkedPersonByName($tree, $owner)
                ?: $owner->memberships()
                    ->where('tree_id', $tree->id)
                    ->with('person')
                    ->first()
                    ?->person
                ?: $this->createPersonForOwner($tree, $owner);

            $membership = TreeMembership::query()->firstOrCreate([
                'tree_id' => $tree->id,
                'user_id' => $owner->id,
            ], [
                'person_id' => $person->id,
                'role' => 'owner',
                'status' => 'approved',
                'approved_by_user_id' => auth()->id() ?: $owner->id,
                'approved_at' => now(),
            ]);

            if ($membership->role !== 'owner' || $membership->status !== 'approved') {
                $membership->update([
                    'role' => 'owner',
                    'status' => 'approved',
                    'approved_by_user_id' => auth()->id() ?: $owner->id,
                    'approved_at' => $membership->approved_at ?: now(),
                ]);
            }

            if (! $membership->person_id) {
                $membership->update(['person_id' => $person->id]);
            }

            return $membership->fresh(['person', 'user']);
        } finally {
            $currentTree->set($previousTree);
        }
    }

    private function findUnlinkedPersonByName(FamilyTree $tree, User $owner): ?Person
    {
        [$firstName, $lastName] = $this->splitName($owner->name);

        return Person::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->where(function ($query) use ($firstName, $lastName): void {
                $query->where(function ($query) use ($firstName, $lastName): void {
                    $query->where('first_name', $firstName)->where('last_name', $lastName);
                })->orWhere(function ($query) use ($firstName, $lastName): void {
                    $query->where('first_name', $lastName)->where('last_name', $firstName);
                });
            })
            ->whereDoesntHave('memberships')
            ->first();
    }

    private function createPersonForOwner(FamilyTree $tree, User $owner): Person
    {
        [$firstName, $lastName] = $this->splitName($owner->name);

        return Person::withoutGlobalScope('family_tree')->create([
            'tree_id' => $tree->id,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'unknown',
            'bio' => 'Карточка владельца дерева. Заполните данные и добавьте фотографию.',
            'is_published' => true,
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $parts = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 0) {
            return ['Владелец', 'дерева'];
        }

        if (count($parts) === 1) {
            return [$parts[0], 'Без фамилии'];
        }

        return [array_shift($parts), implode(' ', $parts)];
    }
}
