<?php

namespace App\Http\Middleware;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveAdminTree
{
    public function __construct(private readonly CurrentTree $currentTree) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            $treeId = (int) ($request->query('tree') ?: $request->session()->get('admin_tree_id'));
            $tree = $treeId ? FamilyTree::query()->find($treeId) : null;

            if ($tree && ! $user->is_super_admin && ! $user->canManageTree($tree)) {
                $tree = null;
            }

            $tree ??= $user->is_super_admin
                ? FamilyTree::query()->oldest('id')->first()
                : $user->trees()
                    ->wherePivot('status', 'approved')
                    ->wherePivotIn('role', ['owner', 'moderator'])
                    ->first();

            if ($tree) {
                $this->currentTree->set($tree);
                $request->session()->put('admin_tree_id', $tree->id);
            }
        }

        return $next($request);
    }
}
