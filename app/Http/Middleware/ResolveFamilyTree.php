<?php

namespace App\Http\Middleware;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveFamilyTree
{
    public function __construct(private readonly CurrentTree $currentTree) {}

    public function handle(Request $request, Closure $next): Response
    {
        $routeTree = $request->route('tree');
        $tree = $request->attributes->get('familyTree');
        $tree = $tree instanceof FamilyTree
            ? $tree
            : ($routeTree instanceof FamilyTree ? $routeTree : null);

        $treeId = (int) $request->header('X-Family-Tree-ID');
        $treeSlug = (string) (
            $request->header('X-Family-Tree')
            ?: $request->query('tree', '')
        );

        if (! $tree && $treeId > 0) {
            $tree = FamilyTree::query()->find($treeId);
        }

        if (! $tree && $treeSlug !== '') {
            $tree = FamilyTree::query()->where('slug', $treeSlug)->first();
        }

        if (! $tree && $request->session()->has('family_tree_id')) {
            $tree = FamilyTree::query()->find($request->session()->get('family_tree_id'));
        }

        $tree ??= $this->currentTree->resolveDefault($request->user());
        abort_unless($tree && $tree->isActive(), 404, 'Семейное дерево не найдено.');

        $this->currentTree->set($tree);
        $request->attributes->set('familyTree', $tree);
        $request->session()->put('family_tree_id', $tree->id);
        if (! $tree->last_activity_at || $tree->last_activity_at->lt(now()->subMinutes(5))) {
            $settings = $tree->settings ?? [];
            unset($settings['dormant_warning_30_sent_at'], $settings['dormant_warning_7_sent_at']);
            $tree->updateQuietly([
                'last_activity_at' => now(),
                'deletion_scheduled_at' => null,
                'settings' => $settings,
            ]);
        }

        return $next($request);
    }
}
