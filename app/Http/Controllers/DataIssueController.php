<?php

namespace App\Http\Controllers;

use App\Models\DataIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DataIssueController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $tree = $request->attributes->get('familyTree');
        $person = $request->attributes->get('familyPerson');
        $familyUser = $request->attributes->get('familyUser');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:5000'],
            'person_id' => [
                'nullable',
                'integer',
                Rule::exists('people', 'id')->where('tree_id', $tree->id),
            ],
        ]);

        $issue = DataIssue::query()->create([
            ...$data,
            'tree_id' => $tree->id,
            'person_id' => $data['person_id'] ?? $person?->id,
            'reported_by_user_id' => $familyUser?->id,
            'status' => 'open',
        ]);

        return response()->json([
            'message' => 'Спасибо. Сообщение передано владельцу дерева.',
            'issue_id' => $issue->id,
        ], 201);
    }
}
