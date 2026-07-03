<?php

namespace App\Http\Middleware;

use App\Models\FamilyTree;
use App\Support\CurrentTree;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ResolveCustomDomain
{
    public function __construct(private readonly CurrentTree $currentTree) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = mb_strtolower($request->getHost());
        $platformHosts = collect(config('platform.domains', []))
            ->push(parse_url((string) config('app.url'), PHP_URL_HOST))
            ->push('localhost')
            ->push('127.0.0.1')
            ->filter()
            ->map(fn ($value): string => mb_strtolower((string) $value));

        if ($platformHosts->contains($host) || app()->isLocal()) {
            return $next($request);
        }

        try {
            if (! Schema::hasTable('family_trees')) {
                return $next($request);
            }
            $tree = FamilyTree::query()
                ->whereRaw('LOWER(primary_domain) = ?', [$host])
                ->where('domain_status', 'active')
                ->where('status', 'active')
                ->first();
        } catch (Throwable) {
            $tree = null;
        }

        abort_unless($tree, 404, 'Собственный домен не подключён.');
        $this->currentTree->set($tree);
        $request->attributes->set('familyTree', $tree);
        $request->attributes->set('customDomainTree', $tree);

        if ($request->is('family/*') || $request->is('family')) {
            return redirect()->to('/');
        }

        return $next($request);
    }
}
