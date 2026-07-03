<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\ParentChild;
use App\Models\Partnership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TreeIntegrityService
{
    /**
     * @return array{issues: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function inspect(FamilyTree $tree): array
    {
        $issues = collect();
        $this->membershipIssues($tree, $issues);
        $this->relationIssues($tree, $issues);
        $this->cycleIssues($tree, $issues);
        $this->componentIssues($tree, $issues);

        return [
            'issues' => $issues->values()->all(),
            'summary' => [
                'errors' => $issues->where('severity', 'error')->count(),
                'warnings' => $issues->where('severity', 'warning')->count(),
                'total' => $issues->count(),
            ],
        ];
    }

    public function removeExactDuplicates(FamilyTree $tree): int
    {
        $deleted = 0;

        ParentChild::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get()
            ->groupBy(fn (ParentChild $link): string => implode(':', [
                $link->parent_id,
                $link->child_id,
                $link->type,
            ]))
            ->each(function (Collection $duplicates) use (&$deleted): void {
                $duplicates->sortBy('id')->skip(1)->each(function (ParentChild $link) use (&$deleted): void {
                    $link->delete();
                    $deleted++;
                });
            });

        Partnership::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get()
            ->groupBy(fn (Partnership $link): string => implode(':', [
                min($link->partner_one_id, $link->partner_two_id),
                max($link->partner_one_id, $link->partner_two_id),
                $link->started_at?->toDateString(),
            ]))
            ->each(function (Collection $duplicates) use (&$deleted): void {
                $duplicates->sortBy('id')->skip(1)->each(function (Partnership $link) use (&$deleted): void {
                    $link->delete();
                    $deleted++;
                });
            });

        return $deleted;
    }

    private function membershipIssues(FamilyTree $tree, Collection $issues): void
    {
        DB::table('tree_memberships as memberships')
            ->leftJoin('people', 'people.id', '=', 'memberships.person_id')
            ->where('memberships.tree_id', $tree->id)
            ->whereNotNull('memberships.person_id')
            ->where(function ($query): void {
                $query->whereNull('people.id')
                    ->orWhereColumn('people.tree_id', '!=', 'memberships.tree_id');
            })
            ->get(['memberships.id', 'memberships.person_id'])
            ->each(fn ($row) => $issues->push([
                'severity' => 'error',
                'type' => 'membership_person_tree',
                'message' => "Участник #{$row->id} привязан к отсутствующему человеку или другому дереву.",
                'record_id' => $row->id,
            ]));

        DB::table('tree_memberships')
            ->where('tree_id', $tree->id)
            ->whereNotNull('person_id')
            ->select('person_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('person_id')
            ->having('aggregate', '>', 1)
            ->get()
            ->each(fn ($row) => $issues->push([
                'severity' => 'error',
                'type' => 'duplicate_person_link',
                'message' => "Человек #{$row->person_id} привязан к {$row->aggregate} учётным записям.",
                'record_id' => $row->person_id,
            ]));
    }

    private function relationIssues(FamilyTree $tree, Collection $issues): void
    {
        foreach ([
            ['parent_children', 'parent_id', 'child_id', 'родительская связь'],
            ['partnerships', 'partner_one_id', 'partner_two_id', 'семейный союз'],
        ] as [$table, $left, $right, $label]) {
            DB::table("{$table} as relations")
                ->leftJoin('people as left_person', 'left_person.id', '=', "relations.{$left}")
                ->leftJoin('people as right_person', 'right_person.id', '=', "relations.{$right}")
                ->where('relations.tree_id', $tree->id)
                ->where(function ($query) use ($tree): void {
                    $query->whereNull('left_person.id')
                        ->orWhereNull('right_person.id')
                        ->orWhere('left_person.tree_id', '!=', $tree->id)
                        ->orWhere('right_person.tree_id', '!=', $tree->id);
                })
                ->get(['relations.id'])
                ->each(fn ($row) => $issues->push([
                    'severity' => 'error',
                    'type' => 'cross_tree_relation',
                    'message' => ucfirst($label)." #{$row->id} ссылается на другое дерево или удалённого человека.",
                    'record_id' => $row->id,
                ]));
        }

        ParentChild::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get()
            ->groupBy(fn (ParentChild $link): string => "{$link->parent_id}:{$link->child_id}:{$link->type}")
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->each(fn (Collection $group) => $issues->push([
                'severity' => 'warning',
                'type' => 'duplicate_parent_link',
                'message' => 'Повторяется одна и та же родительская связь (ID: '.$group->pluck('id')->join(', ').').',
                'record_id' => $group->first()->id,
            ]));

        Partnership::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get()
            ->groupBy(fn (Partnership $link): string => implode(':', [
                min($link->partner_one_id, $link->partner_two_id),
                max($link->partner_one_id, $link->partner_two_id),
                $link->started_at?->toDateString(),
            ]))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->each(fn (Collection $group) => $issues->push([
                'severity' => 'warning',
                'type' => 'duplicate_partnership',
                'message' => 'Повторяется один семейный союз (ID: '.$group->pluck('id')->join(', ').').',
                'record_id' => $group->first()->id,
            ]));
    }

    private function cycleIssues(FamilyTree $tree, Collection $issues): void
    {
        $parents = [];
        ParentChild::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get(['parent_id', 'child_id'])
            ->each(function (ParentChild $link) use (&$parents): void {
                $parents[(int) $link->child_id][] = (int) $link->parent_id;
            });

        $reported = [];
        $walk = function (int $personId, array $path = []) use (&$walk, &$reported, $parents, $issues): void {
            if (isset($path[$personId])) {
                if (! isset($reported[$personId])) {
                    $reported[$personId] = true;
                    $issues->push([
                        'severity' => 'error',
                        'type' => 'ancestry_cycle',
                        'message' => "Обнаружен цикл предков с участием человека #{$personId}.",
                        'record_id' => $personId,
                    ]);
                }

                return;
            }

            $path[$personId] = true;
            foreach ($parents[$personId] ?? [] as $parentId) {
                $walk($parentId, $path);
            }
        };

        foreach (array_keys($parents) as $personId) {
            $walk((int) $personId);
        }
    }

    private function componentIssues(FamilyTree $tree, Collection $issues): void
    {
        $ids = DB::table('people')->where('tree_id', $tree->id)->pluck('id')->map(fn ($id): int => (int) $id);
        if ($ids->count() < 2) {
            return;
        }

        $adjacency = [];
        foreach ($ids as $id) {
            $adjacency[$id] = [];
        }
        ParentChild::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get(['parent_id', 'child_id'])
            ->each(function (ParentChild $link) use (&$adjacency): void {
                $adjacency[$link->parent_id][] = (int) $link->child_id;
                $adjacency[$link->child_id][] = (int) $link->parent_id;
            });
        Partnership::withoutGlobalScope('family_tree')
            ->where('tree_id', $tree->id)
            ->get(['partner_one_id', 'partner_two_id'])
            ->each(function (Partnership $link) use (&$adjacency): void {
                $adjacency[$link->partner_one_id][] = (int) $link->partner_two_id;
                $adjacency[$link->partner_two_id][] = (int) $link->partner_one_id;
            });

        $unseen = array_fill_keys($ids->all(), true);
        $components = [];
        while ($unseen !== []) {
            $start = (int) array_key_first($unseen);
            $stack = [$start];
            $size = 0;
            while ($stack !== []) {
                $current = array_pop($stack);
                if (! isset($unseen[$current])) {
                    continue;
                }
                unset($unseen[$current]);
                $size++;
                foreach ($adjacency[$current] ?? [] as $next) {
                    if (isset($unseen[$next])) {
                        $stack[] = $next;
                    }
                }
            }
            $components[] = $size;
        }

        if (count($components) > 1) {
            rsort($components);
            $issues->push([
                'severity' => 'warning',
                'type' => 'disconnected_components',
                'message' => 'Дерево состоит из '.count($components).' несвязанных частей: '.implode(', ', $components).' человек.',
                'record_id' => null,
            ]);
        }
    }
}
