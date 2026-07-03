<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HelpController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tree = app(CurrentTree::class)->get();
        if (! $tree && $request->filled('tree')) {
            $tree = FamilyTree::query()->where('slug', $request->string('tree'))->first();
        }
        if (! $tree && $request->user()?->last_tree_id) {
            $tree = FamilyTree::query()->find($request->user()->last_tree_id);
        }
        $role = $request->user()?->is_super_admin
            ? 'super_admin'
            : ($tree ? $request->user()?->roleInTree($tree) : null);

        return view('public.help', compact('role', 'tree'));
    }
}
