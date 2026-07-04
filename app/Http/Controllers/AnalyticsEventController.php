<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsEventController extends Controller
{
    public function __invoke(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(AnalyticsService::FRONTEND_EVENTS)],
            'parameters' => ['nullable', 'array', 'max:20'],
            'tree_id' => ['nullable', 'integer'],
        ]);
        $tree = isset($data['tree_id'])
            ? FamilyTree::query()->whereKey($data['tree_id'])->first()
            : null;
        $event = $analytics->record(
            $data['event'],
            $request,
            $request->user(),
            $tree,
            $data['parameters'] ?? [],
        );

        return response()->json(['ok' => (bool) $event]);
    }
}
