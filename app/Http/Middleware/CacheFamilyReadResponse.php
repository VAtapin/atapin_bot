<?php

namespace App\Http\Middleware;

use App\Services\TreeCacheService;
use App\Support\CurrentTree;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheFamilyReadResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $treeId = app(CurrentTree::class)->id();
        $userId = $request->attributes->get('familyUser')?->id ?: 'guest';
        if (! $treeId) {
            return $next($request);
        }
        $cacheScope = match (true) {
            $request->is('api/family/gallery'),
            $request->is('api/family/events') => 'shared',
            $request->is('api/family/birthdays') => 'user:'.$userId,
            default => null,
        };
        if (! $cacheScope) {
            return $next($request);
        }
        $version = app(TreeCacheService::class)->version($treeId);
        $key = 'family-json:'.hash('sha256', implode('|', [
            $treeId,
            $version,
            $cacheScope,
            $request->fullUrl(),
            (string) $request->attributes->get('treePreviewMode'),
        ]));

        if (($cached = Cache::get($key)) !== null) {
            return response()->json($cached)
                ->header('X-Family-Cache', 'HIT');
        }

        $response = $next($request);
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            Cache::put($key, $response->getData(true), now()->addMinutes(5));
            $response->headers->set('X-Family-Cache', 'MISS');
        }

        return $response;
    }
}
