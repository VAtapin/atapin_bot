<?php

namespace App\Http\Middleware;

use App\Models\ChangeLog;
use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyTreePanelContext
{
    public function __construct(private readonly CurrentTree $currentTree) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tree = Filament::getTenant();
        $user = $request->user();

        abort_unless($tree instanceof FamilyTree && $user?->canAccessTenant($tree), 403);

        $this->currentTree->set($tree);
        $request->attributes->set('familyTree', $tree);

        if ($user->last_tree_id !== $tree->id) {
            $user->updateQuietly(['last_tree_id' => $tree->id]);
        }

        $auditKey = "tree_panel_audited.{$tree->id}";
        if ($user->is_super_admin && ! $request->session()->has($auditKey)) {
            ChangeLog::query()->create([
                'tree_id' => $tree->id,
                'user_id' => $user->id,
                'action' => 'platform_admin_entered_tree',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            $request->session()->put($auditKey, now()->toIso8601String());
        }

        return $next($request);
    }
}
