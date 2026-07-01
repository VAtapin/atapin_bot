<?php

namespace App\Http\Controllers;

use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MiniAppController extends Controller
{
    public function index(Request $request, ?Person $person = null): View
    {
        return view('family.app', [
            'familyName' => Setting::value('family_name', 'Наша семья'),
            'telegramAuthError' => session('telegram_auth_error'),
            'loginError' => session('login_error') ?: session('errors')?->first('login'),
            'initialFocusId' => $person?->id,
            'hasBrowserSession' => $request->session()->has('family_person_id')
                || $request->session()->has('family_telegram_user_id'),
            'familyAppConfig' => [
                'authError' => session('telegram_auth_error'),
                'loginError' => session('login_error') ?: session('errors')?->first('login'),
                'telegramLoginUrl' => config('services.telegram.oidc_client_id')
                    ? route('telegram.login')
                    : null,
                'focusId' => $person?->id,
            ],
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $focusId = $this->focusId($request);
        $relationMap = $focusId ? $this->relationMap($focusId) : [];
        $relation = $request->string('relation')->toString();

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
            ->when($request->filled('city'), function (Builder $query) use ($request): void {
                $place = $request->string('city')->toString();
                $query->where(fn (Builder $query) => $query
                    ->where('current_city', $place)
                    ->orWhere('birth_place', $place)
                    ->orWhere('death_place', $place)
                    ->orWhere('burial_place', $place));
            })
            ->when($request->string('living')->isNotEmpty(), function (Builder $query) use ($request): void {
                $request->boolean('living')
                    ? $query->whereNull('death_date')
                    : $query->whereNotNull('death_date');
            })
            ->when($request->integer('birth_month') > 0, fn (Builder $query) => $query->whereMonth('birth_date', $request->integer('birth_month')))
            ->when($relation !== '', function (Builder $query) use ($relation, $relationMap): void {
                $query->whereIn('id', array_keys(array_filter(
                    $relationMap,
                    fn (string $label): bool => $label === $relation,
                )));
            })
            ->orderBy('sort_order')
            ->orderBy('birth_date');

        $totalPeople = Person::query()->where('is_published', true)->count();

        if (
            $relation === ''
            &&
            $request->string('scope')->toString() !== 'all'
            && $request->string('q')->trim()->isEmpty()
            && $focusId
        ) {
            $query->whereIn('id', $this->familyBranchIds(
                $focusId,
                min(max($request->integer('depth', 2), 1), 5),
            ));
        }

        $people = $query->with('photos')->get();

        $ids = $people->pluck('id');
        $parentChild = ParentChild::query()
            ->whereIn('parent_id', $ids)
            ->whereIn('child_id', $ids)
            ->get(['parent_id', 'child_id', 'type']);
        $partnerships = Partnership::query()
            ->whereIn('partner_one_id', $ids)
            ->whereIn('partner_two_id', $ids)
            ->get(['partner_one_id', 'partner_two_id', 'status']);
        $allParentChild = ParentChild::query()->get(['parent_id', 'child_id', 'type']);
        $allPartnerships = Partnership::query()
            ->get(['partner_one_id', 'partner_two_id', 'status', 'started_at', 'ended_at']);
        $relatedIds = $allParentChild->pluck('parent_id')
            ->merge($allParentChild->pluck('child_id'))
            ->merge($allPartnerships->pluck('partner_one_id'))
            ->merge($allPartnerships->pluck('partner_two_id'))
            ->unique();
        $peopleById = Person::query()
            ->with('photos')
            ->whereIn('id', $relatedIds)
            ->get()
            ->keyBy('id');

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
                'photos' => $person->photos->map(fn ($photo): array => [
                    'id' => (string) $photo->id,
                    'url' => $photo->url,
                    'title' => $photo->title,
                ])->values(),
                'relation' => $relationMap[$person->id] ?? null,
                'relatives' => $this->relativeSummary(
                    $person->id,
                    $allParentChild,
                    $allPartnerships,
                    $peopleById,
                ),
            ]),
            'parent_child' => $parentChild,
            'partnerships' => $partnerships,
            'focus_id' => $focusId ? (string) $focusId : null,
            'total_people' => $totalPeople,
            'shown_people' => $people->count(),
            'filters' => [
                'cities' => Person::query()
                    ->where('is_published', true)
                    ->get(['current_city', 'birth_place', 'death_place', 'burial_place'])
                    ->flatMap(fn (Person $person): array => [
                        $person->current_city,
                        $person->birth_place,
                        $person->death_place,
                        $person->burial_place,
                    ])
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values(),
            ],
        ]);
    }

    public function gallery(): JsonResponse
    {
        $photos = PersonPhoto::query()
            ->with(['person', 'album'])
            ->whereHas('person', fn (Builder $query) => $query->where('is_published', true))
            ->latest('taken_at')
            ->latest('id')
            ->get()
            ->filter(fn ($photo): bool => filled($photo->url))
            ->map(fn ($photo): array => [
                'id' => (string) $photo->id,
                'url' => $photo->url,
                'title' => $photo->title,
                'description' => $photo->description,
                'taken_at' => $photo->taken_at?->toDateString(),
                'album' => $photo->album?->title,
                'person_id' => (string) $photo->person_id,
                'person_name' => $photo->person->full_name,
            ])
            ->values();

        return response()->json(['photos' => $photos]);
    }

    public function navigation(Request $request): JsonResponse
    {
        $telegramUser = $request->attributes->get('telegramUser');

        if (! $telegramUser) {
            return response()->json(['action' => null]);
        }

        $action = $telegramUser->mini_app_action;

        if ($action) {
            $telegramUser->updateQuietly(['mini_app_action' => null]);
        }

        return response()->json(['action' => $action]);
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

    private function focusId(Request $request): ?int
    {
        $focusId = $request->integer('focus') ?: null;
        $telegramUser = $request->attributes->get('telegramUser');

        if (! $focusId && $telegramUser?->person_id) {
            $focusId = $telegramUser->person_id;
        }

        if (! $focusId && $request->attributes->get('familyPerson')) {
            $focusId = $request->attributes->get('familyPerson')->id;
        }

        return $focusId ?: (int) Setting::value(
            'tree_default_person_id',
            Person::query()
                ->where('is_published', true)
                ->whereNotNull('birth_date')
                ->oldest('birth_date')
                ->value('id'),
        ) ?: null;
    }

    /**
     * @return array<int, string>
     */
    private function relationMap(int $focusId): array
    {
        $links = ParentChild::query()->get(['parent_id', 'child_id']);
        $partnerships = Partnership::query()->get(['partner_one_id', 'partner_two_id']);
        $parents = [];
        $children = [];

        foreach ($links as $link) {
            $parents[$link->child_id][] = $link->parent_id;
            $children[$link->parent_id][] = $link->child_id;
        }

        $map = [$focusId => 'self'];
        $parentIds = $parents[$focusId] ?? [];
        $childIds = $children[$focusId] ?? [];

        foreach ($parentIds as $id) {
            $map[$id] = 'parents';
            foreach ($parents[$id] ?? [] as $grandparentId) {
                $map[$grandparentId] ??= 'grandparents';
            }
        }

        foreach ($childIds as $id) {
            $map[$id] = 'children';
            foreach ($children[$id] ?? [] as $grandchildId) {
                $map[$grandchildId] ??= 'grandchildren';
            }
        }

        $siblingIds = [];
        foreach ($parentIds as $parentId) {
            foreach ($children[$parentId] ?? [] as $siblingId) {
                if ($siblingId !== $focusId) {
                    $siblingIds[$siblingId] = true;
                    $map[$siblingId] ??= 'siblings';
                }
            }
        }

        foreach (array_keys($siblingIds) as $siblingId) {
            foreach ($children[$siblingId] ?? [] as $nephewId) {
                $map[$nephewId] ??= 'nephews';
            }
        }

        foreach ($partnerships as $partnership) {
            if ($partnership->partner_one_id === $focusId) {
                $map[$partnership->partner_two_id] = 'spouses';
            } elseif ($partnership->partner_two_id === $focusId) {
                $map[$partnership->partner_one_id] = 'spouses';
            }
        }

        return $map;
    }

    private function relativeSummary(
        int $personId,
        $links,
        $partnerships,
        $peopleById,
    ): array {
        $personData = fn (int $id): ?array => ($person = $peopleById->get($id))
            ? [
                'id' => (string) $person->id,
                'name' => $person->full_name,
                'photo_url' => $person->photo_url,
                'life_years' => $person->life_years,
            ]
            : null;

        $parents = $links
            ->where('child_id', $personId)
            ->map(fn ($link) => $personData($link->parent_id))
            ->filter()
            ->values();
        $children = $links
            ->where('parent_id', $personId)
            ->map(fn ($link) => $personData($link->child_id))
            ->filter()
            ->values();
        $spouses = $partnerships
            ->filter(fn ($link): bool => $link->partner_one_id === $personId || $link->partner_two_id === $personId)
            ->map(function ($link) use ($personId, $personData): ?array {
                $relative = $personData(
                    $link->partner_one_id === $personId
                        ? $link->partner_two_id
                        : $link->partner_one_id,
                );

                return $relative ? [...$relative, 'status' => $link->status] : null;
            })
            ->filter()
            ->values();

        return compact('parents', 'spouses', 'children');
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
