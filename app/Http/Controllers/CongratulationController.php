<?php

namespace App\Http\Controllers;

use App\Models\Partnership;
use App\Models\Person;
use App\Services\CongratulationService;
use App\Support\CurrentTree;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class CongratulationController extends Controller
{
    public function store(Request $request, CongratulationService $service): JsonResponse
    {
        $data = $request->validate([
            'occasion' => ['required', 'in:birthday,anniversary'],
            'message' => ['required', 'string', 'min:2', 'max:1000'],
            'person_id' => ['nullable', 'integer'],
            'partnership_id' => ['nullable', 'integer'],
        ]);
        if (! ($data['person_id'] ?? null) && ! ($data['partnership_id'] ?? null)) {
            throw ValidationException::withMessages(['recipient' => 'Выберите получателя поздравления.']);
        }

        $user = $request->attributes->get('familyUser');
        $membership = $request->attributes->get('treeMembership');
        $tree = app(CurrentTree::class)->get();
        $key = "congratulations:{$tree->id}:{$user->id}";
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'message' => 'Слишком много поздравлений. Повторите через '
                    .RateLimiter::availableIn($key).' сек.',
            ]);
        }
        RateLimiter::hit($key, 60);

        $person = ! empty($data['person_id'])
            ? Person::query()->findOrFail($data['person_id'])
            : null;
        $partnership = ! empty($data['partnership_id'])
            ? Partnership::query()->with(['partnerOne', 'partnerTwo'])->findOrFail($data['partnership_id'])
            : null;
        $items = $service->send(
            $tree,
            $user,
            $membership,
            $data['occasion'],
            $data['message'],
            $person,
            $partnership,
        );

        return response()->json([
            'message' => 'Поздравление сохранено.',
            'deliveries' => $items->map(fn ($item): array => [
                'person_id' => (string) $item->recipient_person_id,
                'site' => $item->site_status,
                'telegram' => $item->telegram_status,
            ])->values(),
        ], 201);
    }
}
