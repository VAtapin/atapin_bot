<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class TreeChooserController extends Controller
{
    public function __invoke(Request $request): View
    {
        $memberships = $request->user()
            ->memberships()
            ->with('tree')
            ->where('status', 'approved')
            ->whereHas('tree', fn ($query) => $query->whereIn('status', ['active', 'deleting']))
            ->orderBy('tree_id')
            ->get();

        return view('public.trees', compact('memberships'));
    }
}
