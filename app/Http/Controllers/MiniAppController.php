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
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $people = Person::query()
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
            ->orderBy('birth_date')
            ->get();

        $ids = $people->pluck('id');

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
                'city' => $person->current_city,
                'occupation' => $person->occupation,
                'bio' => $person->bio,
                'photo_url' => $person->photo_url,
            ]),
            'parent_child' => ParentChild::query()
                ->whereIn('parent_id', $ids)
                ->whereIn('child_id', $ids)
                ->get(['parent_id', 'child_id', 'type']),
            'partnerships' => Partnership::query()
                ->whereIn('partner_one_id', $ids)
                ->whereIn('partner_two_id', $ids)
                ->get(['partner_one_id', 'partner_two_id', 'status']),
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
