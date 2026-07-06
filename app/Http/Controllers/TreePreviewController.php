<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Support\FamilyTreeUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TreePreviewController extends Controller
{
    public function __invoke(Request $request, FamilyTree $tree, string $mode): RedirectResponse
    {
        abort_unless($request->user()?->canManageTree($tree), 403);
        abort_unless(in_array($mode, ['normal', 'member', 'guest'], true), 404);
        $key = "tree_preview_role.{$tree->id}";
        if ($mode === 'normal') {
            $request->session()->forget($key);
        } else {
            $request->session()->put($key, $mode);
        }

        return redirect()->to(app(FamilyTreeUrl::class)->tree($tree));
    }
}
