<?php

namespace App\Http\Controllers;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MiniAppController extends Controller
{
    public function index(): View
    {
        return view('family.app', [
            'familyName' => Setting::value('family_name', 'Наша семья'),
            'telegramAuthError' => session('telegram_auth_error'),
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $query = Person::query()
            ->where('is_published', true)
            ->when($request->string('q')->trim()->isNotEmpty(), function (Builder $query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->where('first_name', 'like', $term)
                        ->orWhere('middle_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('maiden_name', 'like', $term);
                });
            })
            ->when($request->filled('gender'), fn (Builder $query) => $query->where('gender', $request->string('gender')))
            ->when($request->filled('city'), fn (Builder $query) => $query->where('current_city', $request->string('city')))
            ->when($request->string('living')->isNotEmpty(), function (Builder $query) use ($request): void {
                $request->boolean('living')
                    ? $query->whereNull('death_date')
                    : $query->whereNotNull('death_date');
            })
            ->when($request->integer('birth_month') > 0, fn (Builder $query) => $query->whereMonth('birth_date', $request->integer('birth_month')))
            ->orderBy('sort_order')
            ->orderBy('birth_date');

        $totalPeople = Person::query()->where('is_published', true)->count();
        $focusId = $request->integer('focus') ?: null;
        $telegramUser = $request->attributes->get('telegramUser');

        if (! $focusId && $telegramUser?->person_id) {
            $focusId = $telegramUser->person_id;
        }

        if (! $focusId) {
            $focusId = (int) Setting::value(
                'tree_default_person_id',
                Person::query()
                    ->where('is_published', true)
                    ->whereNotNull('birth_date')
                    ->oldest('birth_date')
                    ->value('id'),
            );
        }

        if (
            $request->string('scope')->toString() !== 'all'
            && $request->string('q')->trim()->isEmpty()
            && $focusId
        ) {
            $query->whereIn('id', $this->familyBranchIds(
                $focusId,
                min(max($request->integer('depth', 2), 1), 5),
            ));
        }

        $people = $query->get();

        $ids = $people->pluck('id');
        $parentChild = ParentChild::query()
            ->whereIn('parent_id', $ids)
            ->whereIn('child_id', $ids)
            ->get(['parent_id', 'child_id', 'type']);
        $partnerships = Partnership::query()
            ->whereIn('partner_one_id', $ids)
            ->whereIn('partner_two_id', $ids)
            ->get(['partner_one_id', 'partner_two_id', 'status']);

        return response()->json([
            'people' => $people->map(fn (Person $person): array => [
                'id' => (string) $person->id,
                'name' => $person->full_name,
                'maiden_name' => $person->maiden_name,
                'gender' => $person->gender,
                'birth_date' => $person->birth_date?->toDateString(),
                'death_date' => $person->death_date?->toDateString(),
                'life_years' => $person->life_years,
                'birth_place' => $person->birth_place,
                'death_place' => $person->death_place,
                'burial_place' => $person->burial_place,
                'city' => $person->current_city,
                'address' => $person->current_address,
                'occupation' => $person->occupation,
                'bio' => $person->bio,
                'photo_url' => $person->photo_url,
            ]),
            'parent_child' => $parentChild,
            'partnerships' => $partnerships,
            'focus_id' => $focusId ? (string) $focusId : null,
            'total_people' => $totalPeople,
            'shown_people' => $people->count(),
            'filters' => [
                'cities' => Person::query()
                    ->where('is_published', true)
                    ->whereNotNull('current_city')
                    ->distinct()
                    ->orderBy('current_city')
                    ->pluck('current_city'),
            ],
        ]);
    }

    private function familyBranchIds(int $focusId, int $depth): array
    {
        $parentLinks = ParentChild::query()->get(['parent_id', 'child_id']);
        $partnerships = Partnership::query()->get(['partner_one_id', 'partner_two_id']);
        $adjacency = [];

        foreach ($parentLinks as $link) {
            $adjacency[$link->parent_id][] = $link->child_id;
            $adjacency[$link->child_id][] = $link->parent_id;
        }

        foreach ($partnerships as $partnership) {
            $adjacency[$partnership->partner_one_id][] = $partnership->partner_two_id;
            $adjacency[$partnership->partner_two_id][] = $partnership->partner_one_id;
        }

        $visited = [$focusId => true];
        $frontier = [$focusId];

        for ($level = 0; $level < $depth; $level++) {
            $next = [];

            foreach ($frontier as $personId) {
                foreach ($adjacency[$personId] ?? [] as $relativeId) {
                    if (isset($visited[$relativeId])) {
                        continue;
                    }

                    $visited[$relativeId] = true;
                    $next[] = $relativeId;
                }
            }

            $frontier = $next;

            if ($frontier === []) {
                break;
            }
        }

        return array_keys($visited);
    }

    public function birthdays(): JsonResponse
    {
        $today = now()->startOfDay();

        $birthdays = Person::query()
            ->where('is_published', true)
            ->whereNull('death_date')
            ->whereNotNull('birth_date')
            ->get()
            ->map(function (Person $person) use ($today): array {
                $next = Carbon::create(
                    $today->year,
                    $person->birth_date->month,
                    min($person->birth_date->day, Carbon::create($today->year, $person->birth_date->month)->daysInMonth),
                );

                if ($next->lt($today)) {
                    $next->addYear();
                }

                return [
                    'id' => (string) $person->id,
                    'name' => $person->full_name,
                    'date' => $next->toDateString(),
                    'days' => $today->diffInDays($next),
                    'age' => $person->birth_date->diffInYears($next),
                    'photo_url' => $person->photo_url,
                ];
            })
            ->sortBy('days')
            ->take(20)
            ->values();

        return response()->json(['birthdays' => $birthdays]);
    }
}
