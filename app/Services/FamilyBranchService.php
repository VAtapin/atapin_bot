<?php

namespace App\Services;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Support\CurrentTree;

class FamilyBranchService
{
    /**
     * Return blood relatives reachable through parent-child edges, then attach
     * their spouses without walking into the spouses' own blood families.
     *
     * @return array<int>
     */
    public function branchIds(int $focusId, int $depth): array
    {
        $depth = min(max($depth, 1), 8);
        $treeId = (int) app(CurrentTree::class)->id();
        if (! $treeId) {
            return $this->calculate($focusId, $depth);
        }

        return app(TreeCacheService::class)->remember(
            $treeId,
            "branch:{$focusId}:{$depth}",
            fn (): array => $this->calculate($focusId, $depth),
        );
    }

    /**
     * @return array<int>
     */
    private function calculate(int $focusId, int $depth): array
    {
        $links = ParentChild::query()->get(['parent_id', 'child_id']);
        $bloodAdjacency = [];

        foreach ($links as $link) {
            $bloodAdjacency[$link->parent_id][] = (int) $link->child_id;
            $bloodAdjacency[$link->child_id][] = (int) $link->parent_id;
        }

        $visited = [$focusId => true];
        $frontier = [$focusId];

        for ($generation = 0; $generation < $depth; $generation++) {
            $next = [];
            foreach ($frontier as $personId) {
                foreach ($bloodAdjacency[$personId] ?? [] as $relativeId) {
                    if (isset($visited[$relativeId])) {
                        continue;
                    }

                    $visited[$relativeId] = true;
                    $next[] = $relativeId;
                }
            }

            if ($next === []) {
                break;
            }
            $frontier = $next;
        }

        $bloodIds = array_keys($visited);
        Partnership::query()
            ->where(fn ($query) => $query
                ->whereIn('partner_one_id', $bloodIds)
                ->orWhereIn('partner_two_id', $bloodIds))
            ->get(['partner_one_id', 'partner_two_id'])
            ->each(function (Partnership $partnership) use (&$visited, $bloodIds): void {
                if (in_array((int) $partnership->partner_one_id, $bloodIds, true)) {
                    $visited[(int) $partnership->partner_two_id] = true;
                }
                if (in_array((int) $partnership->partner_two_id, $bloodIds, true)) {
                    $visited[(int) $partnership->partner_one_id] = true;
                }
            });

        return array_map('intval', array_keys($visited));
    }
}
