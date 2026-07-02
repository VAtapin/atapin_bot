<?php

namespace App\Http\Controllers;

use App\Models\ChangeLog;
use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LeaveTreeManagementController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $treeId = (int) $request->session()->pull('family_tree_id');
        $tree = $treeId > 0 ? FamilyTree::query()->find($treeId) : null;

        if ($tree && $request->user()?->is_super_admin) {
            ChangeLog::query()->create([
                'tree_id' => $tree->id,
                'user_id' => $request->user()->id,
                'action' => 'platform_admin_left_tree',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $request->session()->forget("tree_panel_audited.{$tree->id}");
        }

        app(CurrentTree::class)->clear();

        return $request->user()?->is_super_admin
            ? redirect('/admin')
            : redirect()->route('trees.choose');
    }
}
