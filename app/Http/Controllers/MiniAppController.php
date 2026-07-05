<?php

namespace App\Http\Controllers;

use App\Models\Congratulation;
use App\Models\FamilyEvent;
use App\Models\FamilyTree;
use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\Setting;
use App\Services\AnalyticsService;
use App\Services\ColorContrast;
use App\Services\FamilyBranchService;
use App\Services\TreeCacheService;
use App\Support\CurrentTree;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MiniAppController extends Controller
{
    public function index(
        Request $request,
        ?FamilyTree $tree = null,
        ?Person $person = null,
    ): View {
        $tree ??= app(CurrentTree::class)->get();
        $platform = $request->has('tgWebAppPlatform')
            ? 'telegram'
            : ($request->has('vk_user_id') && $request->has('sign')
                ? 'vk'
                : mb_strtolower($request->string('platform', 'web')->toString()));
        $platform = in_array($platform, ['web', 'telegram', 'vk', 'ok', 'max'], true) ? $platform : 'web';
        if ($tree) {
            app(AnalyticsService::class)->record(
                'view_family_tree_landing',
                $request,
                $request->user(),
                $tree,
                ['tree_id' => $tree->id],
            );
        }
        if ($person && $tree) {
            abort_unless((int) $person->tree_id === (int) $tree->id, 404);
        }

        return view('family.app', [
            'familyName' => $tree?->name ?: Setting::value('family_name', __('miniapp.default_family_name')),
            'familySubtitle' => $tree?->subtitle ?: __('miniapp.default_family_subtitle'),
            'familyCrestUrl' => $tree?->crest_url,
            'familyAccent' => $tree?->accent_color ?: '#68734b',
            'familyAccentText' => app(ColorContrast::class)->foreground($tree?->accent_color),
            'telegramAuthError' => session('telegram_auth_error'),
            'loginError' => session('login_error') ?: session('errors')?->first('login'),
            'initialFocusId' => $person?->id,
            'hasBrowserSession' => $request->session()->has('family_person_id')
                || $request->session()->has('family_telegram_user_id')
                || $request->session()->has('family_user_id'),
            'familyAppConfig' => [
                'authError' => session('telegram_auth_error'),
                'loginError' => session('login_error') ?: session('errors')?->first('login'),
                'telegramLoginUrl' => config('services.telegram.oidc_client_id')
                    ? rtrim((string) config('app.url'), '/').'/auth/telegram?'.http_build_query(array_filter([
                        'tree' => $tree?->slug,
                        'return' => '/'.ltrim($request->getRequestUri(), '/'),
                        'return_host' => $request->attributes->get('customDomainTree')
                            ? $request->getHost()
                            : null,
                    ]))
                    : null,
                'telegramCredentialsUrl' => 'https://t.me/'
                    .ltrim((string) config('services.telegram.bot_username'), '@')
                    .'?start=credentials',
                'focusId' => $person?->id,
                'openPersonId' => $person?->id,
                'treeId' => $tree?->id,
                'treeSlug' => $tree?->slug,
                'accentColor' => $tree?->accent_color,
                'platform' => $platform,
                'vkAppId' => config('services.vk.app_id'),
                'managementUrl' => $tree && $request->user()?->canManageTree($tree)
                    ? '/manage/'.$tree->slug
                    : null,
                'previewMode' => $request->attributes->get('treePreviewMode'),
            ],
        ]);
    }

    public function tree(Request $request): JsonResponse
    {
        $focusId = $this->focusId($request);
        $relationMap = $focusId ? $this->relationMap($focusId) : [];
        $relation = $request->string('relation')->toString();
        $personColumns = [
            'id',
            'tree_id',
            'first_name',
            'middle_name',
            'last_name',
            'maiden_name',
            'married_name',
            'gender',
            'birth_date',
            'birth_date_precision',
            'death_date',
            'death_date_precision',
            'birth_place',
            'death_place',
            'burial_place',
            'current_city',
            'current_address',
            'occupation',
            'bio',
            'photo_path',
            'photo_thumbnail_path',
            'is_published',
            'sort_order',
        ];
        $photoColumns = [
            'id',
            'tree_id',
            'person_id',
            'path',
            'thumbnail_path',
            'source_url',
            'title',
            'is_primary',
            'sort_order',
        ];

        $query = Person::query()
            ->select($personColumns)
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
            $query->whereIn('id', app(FamilyBranchService::class)->branchIds(
                $focusId,
                min(max($request->integer('depth', 2), 1), 5),
            ));
        }

        $people = $query
            ->with(['photos' => fn ($query) => $query->select($photoColumns)])
            ->get();

        $ids = $people->pluck('id');
        $parentChild = ParentChild::query()
            ->whereIn('parent_id', $ids)
            ->whereIn('child_id', $ids)
            ->get(['parent_id', 'child_id', 'type']);
        $partnerships = Partnership::query()
            ->whereIn('partner_one_id', $ids)
            ->whereIn('partner_two_id', $ids)
            ->get([
                'partner_one_id',
                'partner_two_id',
                'status',
                'started_at',
                'ended_at',
            ]);
        $treeId = (int) app(CurrentTree::class)->id();
        // Cache only plain arrays. Serializing an Eloquent Collection into a
        // persistent cache may produce __PHP_Incomplete_Class after deploy,
        // because PHP tries to restore it before all model classes are loaded.
        $allParentChild = collect(app(TreeCacheService::class)->remember(
            $treeId,
            'parent-child-v2',
            fn (): array => ParentChild::query()
                ->get(['parent_id', 'child_id', 'type'])
                ->map(fn (ParentChild $link): array => [
                    'parent_id' => (int) $link->parent_id,
                    'child_id' => (int) $link->child_id,
                    'type' => $link->type,
                ])
                ->all(),
        ))->map(fn (array $link): object => (object) $link);
        $allPartnerships = collect(app(TreeCacheService::class)->remember(
            $treeId,
            'partnerships-v2',
            fn (): array => Partnership::query()
                ->get(['partner_one_id', 'partner_two_id', 'status', 'started_at', 'ended_at'])
                ->map(fn (Partnership $link): array => [
                    'partner_one_id' => (int) $link->partner_one_id,
                    'partner_two_id' => (int) $link->partner_two_id,
                    'status' => $link->status,
                    'started_at' => $link->started_at?->toDateString(),
                    'ended_at' => $link->ended_at?->toDateString(),
                ])
                ->all(),
        ))->map(fn (array $link): object => (object) $link);
        $relatedIds = $allParentChild->pluck('parent_id')
            ->merge($allParentChild->pluck('child_id'))
            ->merge($allPartnerships->pluck('partner_one_id'))
            ->merge($allPartnerships->pluck('partner_two_id'))
            ->unique();
        $peopleById = Person::query()
            ->select($personColumns)
            ->with(['photos' => fn ($query) => $query->select($photoColumns)])
            ->whereIn('id', $relatedIds)
            ->get()
            ->keyBy('id');
        $listGroups = $this->listGroups($people, $allParentChild, $allPartnerships, $peopleById);

        return response()->json([
            'people' => $people->map(fn (Person $person): array => [
                'id' => (string) $person->id,
                'name' => $person->full_name,
                'maiden_name' => $person->maiden_name,
                'gender' => $person->gender,
                'birth_date' => $person->birth_date?->toDateString(),
                'birth_date_precision' => $person->birth_date_precision,
                'death_date' => $person->death_date?->toDateString(),
                'death_date_precision' => $person->death_date_precision,
                'life_years' => $person->life_years,
                'birth_place' => $person->birth_place,
                'death_place' => $person->death_place,
                'burial_place' => $person->burial_place,
                'city' => $person->current_city,
                'address' => $person->current_address,
                'occupation' => $person->occupation,
                'bio' => $person->bio,
                'photo_url' => $this->personThumbnail($person),
                'photos' => $person->photos->map(fn ($photo): array => [
                    'id' => (string) $photo->id,
                    'url' => $photo->url,
                    'thumbnail_url' => $photo->thumbnail_url ?: asset('images/photo-placeholder.svg'),
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
            'tree' => [
                'id' => (string) app(CurrentTree::class)->id(),
                'slug' => app(CurrentTree::class)->get()?->slug,
                'name' => app(CurrentTree::class)->get()?->name,
            ],
            'viewer' => [
                'person_id' => $request->attributes->get('treeMembership')?->person_id
                    ? (string) $request->attributes->get('treeMembership')->person_id
                    : null,
                'role' => $request->attributes->get('treeMembership')?->role,
                'has_person' => (bool) $request->attributes->get('treeMembership')?->person_id,
                'unread_congratulations' => $request->attributes->get('treeMembership')?->person_id
                    ? Congratulation::query()
                        ->where('recipient_person_id', $request->attributes->get('treeMembership')->person_id)
                        ->whereNull('read_at')
                        ->count()
                    : 0,
            ],
            'focus_id' => $focusId ? (string) $focusId : null,
            'total_people' => $totalPeople,
            'shown_people' => $people->count(),
            'list_groups' => $listGroups,
            'filters' => [
                'cities' => app(TreeCacheService::class)->remember(
                    $treeId,
                    'cities-v2',
                    fn (): array => Person::query()
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
                        ->values()
                        ->all(),
                ),
            ],
        ]);
    }

    private function listGroups($people, $parentChild, $partnerships, $peopleById): array
    {
        $visible = $people->keyBy('id');
        $knownPeople = $visible->union($peopleById);
        $parentsByChild = $parentChild
            ->groupBy('child_id')
            ->map(fn ($links) => $links->pluck('parent_id')->map('intval')->unique()->sort()->values());
        $groups = [];

        foreach ($people as $person) {
            $parentIds = $parentsByChild->get($person->id, collect());

            if ($parentIds->isNotEmpty()) {
                $key = 'parents:'.$parentIds->implode('-');
                $label = $parentIds
                    ->map(fn (int $id): ?string => $knownPeople->get($id)?->full_name)
                    ->filter()
                    ->implode(' · ');
            } else {
                $partnership = $partnerships
                    ->filter(fn ($link): bool => (int) $link->partner_one_id === (int) $person->id
                        || (int) $link->partner_two_id === (int) $person->id)
                    ->sortByDesc(fn ($link): string => ($link->ended_at ? '0' : '1')
                        .($link->started_at ?? '0000-00-00'))
                    ->first();

                if ($partnership) {
                    $partnerIds = collect([
                        (int) $partnership->partner_one_id,
                        (int) $partnership->partner_two_id,
                    ])->sort()->values();
                    $key = 'couple:'.$partnerIds->implode('-');
                    $label = $partnerIds
                        ->map(fn (int $id): ?string => $knownPeople->get($id)?->full_name)
                        ->filter()
                        ->implode(' · ');
                } else {
                    $key = 'person:'.$person->id;
                    $label = $person->full_name;
                }
            }

            $groups[$key] ??= ['label' => $label, 'people' => []];
            $groups[$key]['people'][] = $person;
        }

        return collect($groups)
            ->map(function (array $group): array {
                $sorted = collect($group['people'])
                    ->sortBy(fn (Person $person): string => $person->birth_date?->format('Y-m-d')
                        ?: '9999-12-31')
                    ->values();

                return [
                    'label' => $group['label'],
                    'person_ids' => $sorted->map(fn (Person $person): string => (string) $person->id)->all(),
                    'oldest_birth_date' => $sorted->first()?->birth_date?->format('Y-m-d'),
                ];
            })
            ->sortBy(fn (array $group): string => $group['oldest_birth_date'] ?: '9999-12-31')
            ->values()
            ->all();
    }

    public function gallery(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 36), 12), 60);
        $paginator = PersonPhoto::query()
            ->with(['person', 'album'])
            ->whereHas('person', fn (Builder $query) => $query
                ->where('is_published', true)
                ->where(fn (Builder $query) => $query
                    ->whereNull('gedcom_id')
                    ->orWhere('gedcom_id', '!=', 'I88888888')))
            ->latest('taken_at')
            ->latest('id')
            ->cursorPaginate($perPage);
        $photos = collect($paginator->items());
        $photos = $this->withoutGedcomCutoutDuplicates($photos)
            ->groupBy(function (PersonPhoto $photo): string {
                $source = (string) ($photo->source_url ?: $photo->path ?: $photo->id);
                $parts = parse_url($source);

                return isset($parts['host'])
                    ? mb_strtolower($parts['host'].($parts['path'] ?? ''))
                    : mb_strtolower(str_replace('\\', '/', $source));
            })
            ->map(fn ($duplicates): PersonPhoto => $duplicates
                ->sortBy(fn (PersonPhoto $photo): int => $photo->person->gedcom_id === 'I88888888' ? 1 : 0)
                ->first())
            ->filter(fn ($photo): bool => filled($photo->url))
            ->map(function ($photo): array {
                return [
                    'id' => (string) $photo->id,
                    'url' => $photo->url,
                    'thumbnail_url' => $photo->thumbnail_url ?: asset('images/photo-placeholder.svg'),
                    'title' => $photo->title,
                    'description' => $photo->description,
                    'taken_at' => $photo->taken_at?->toDateString(),
                    'person_id' => (string) $photo->person_id,
                    'person_name' => $photo->person->full_name,
                    'is_associated' => true,
                ];
            })
            ->values();

        return response()->json([
            'photos' => $photos,
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    private function withoutGedcomCutoutDuplicates($photos)
    {
        $originalPhotoIds = PersonPhoto::query()
            ->whereNotNull('gedcom_data')
            ->get(['gedcom_data'])
            ->filter(fn (PersonPhoto $photo): bool => ($photo->gedcom_data['_PARENTPHOTO'] ?? null) === 'Y')
            ->map(fn (PersonPhoto $photo) => $photo->gedcom_data['_PHOTO_RIN'] ?? null)
            ->filter()
            ->flip();

        return $photos
            ->reject(function (PersonPhoto $photo) use ($originalPhotoIds): bool {
                $data = $photo->gedcom_data ?? [];
                $parentPhotoId = $data['_PARENTRIN'] ?? null;
                $isDerivedCutout = ($data['_CUTOUT'] ?? null) === 'Y'
                    || ($data['_PERSONALPHOTO'] ?? null) === 'Y';

                return $isDerivedCutout
                    && $parentPhotoId
                    && $originalPhotoIds->has($parentPhotoId);
            })
            ->values();
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

    private function focusId(Request $request): ?int
    {
        $focusId = $request->integer('focus') ?: null;
        $treeId = app(CurrentTree::class)->id();

        if ($focusId && ! Person::query()->whereKey($focusId)->exists()) {
            $focusId = null;
        }

        $membership = $request->attributes->get('treeMembership');
        if (! $focusId && $membership?->person_id) {
            $focusId = (int) $membership->person_id;
        }

        if (! $focusId && $request->attributes->get('familyPerson')) {
            $focusId = (int) $request->attributes->get('familyPerson')->id;
        }

        if (! $focusId) {
            $focusId = (int) (app(CurrentTree::class)->get()?->start_person_id ?: 0);
        }

        return $focusId && Person::query()
            ->whereKey($focusId)
            ->where('tree_id', $treeId)
            ->where('is_published', true)
            ->exists()
                ? $focusId
                : null;
    }

    /**
     * @return array<int, string>
     */
    private function relationMap(int $focusId): array
    {
        $treeId = (int) app(CurrentTree::class)->id();
        if ($treeId) {
            return app(TreeCacheService::class)->remember(
                $treeId,
                "relations:{$focusId}",
                fn (): array => $this->calculateRelationMap($focusId),
            );
        }

        return $this->calculateRelationMap($focusId);
    }

    /**
     * @return array<int, string>
     */
    private function calculateRelationMap(int $focusId): array
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
                'photo_url' => $this->personThumbnail($person),
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
            ->with('photos')
            ->where('is_published', true)
            ->whereNull('death_date')
            ->whereNotNull('birth_date')
            ->where('birth_date_precision', 'day')
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
                    'photo_url' => $this->personThumbnail($person),
                ];
            })
            ->sortBy('days')
            ->take(20)
            ->values();

        $anniversaries = Partnership::query()
            ->with(['partnerOne.photos', 'partnerTwo.photos'])
            ->whereNotNull('started_at')
            ->whereNull('ended_at')
            ->whereIn('status', ['married', 'partners'])
            ->get()
            ->map(function (Partnership $partnership) use ($today): array {
                $next = Carbon::create(
                    $today->year,
                    $partnership->started_at->month,
                    min(
                        $partnership->started_at->day,
                        Carbon::create($today->year, $partnership->started_at->month)->daysInMonth,
                    ),
                );
                if ($next->lt($today)) {
                    $next->addYear();
                }

                return [
                    'id' => (string) $partnership->id,
                    'title' => __('miniapp.pair_names', [
                        'one' => $partnership->partnerOne->full_name,
                        'two' => $partnership->partnerTwo->full_name,
                    ]),
                    'date' => $next->toDateString(),
                    'days' => $today->diffInDays($next),
                    'years' => $partnership->started_at->diffInYears($next),
                    'partner_one' => [
                        'id' => (string) $partnership->partnerOne->id,
                        'name' => $partnership->partnerOne->full_name,
                        'photo_url' => $this->personThumbnail($partnership->partnerOne),
                    ],
                    'partner_two' => [
                        'id' => (string) $partnership->partnerTwo->id,
                        'name' => $partnership->partnerTwo->full_name,
                        'photo_url' => $this->personThumbnail($partnership->partnerTwo),
                    ],
                ];
            })
            ->sortBy('days')
            ->take(20)
            ->values();

        $viewerPersonId = request()->attributes->get('treeMembership')?->person_id;
        $congratulations = $viewerPersonId
            ? Congratulation::query()
                ->with('senderUser:id,name')
                ->where('recipient_person_id', $viewerPersonId)
                ->latest('id')
                ->limit(30)
                ->get()
                ->map(fn (Congratulation $item): array => [
                    'id' => (string) $item->id,
                    'from' => $item->senderUser?->name ?: __('miniapp.family_member'),
                    'message' => $item->message,
                    'occasion' => $item->occasion,
                    'created_at' => $item->created_at?->toIso8601String(),
                ])
            : collect();
        if ($viewerPersonId) {
            Congratulation::query()
                ->where('recipient_person_id', $viewerPersonId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return response()->json([
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries,
            'congratulations' => $congratulations,
            'viewer_person_id' => $viewerPersonId ? (string) $viewerPersonId : null,
        ]);
    }

    public function events(): JsonResponse
    {
        $today = now()->startOfDay();
        $events = FamilyEvent::query()
            ->with('person')
            ->where('is_published', true)
            ->get()
            ->map(function (FamilyEvent $event) use ($today): array {
                $nextDate = $event->event_date->copy();
                if ($event->is_annual) {
                    $nextDate->year($today->year);
                    if ($nextDate->lt($today)) {
                        $nextDate->addYear();
                    }
                }

                return [
                    'id' => (string) $event->id,
                    'type' => $event->type,
                    'title' => $event->title,
                    'description' => $event->description,
                    'date' => $nextDate->toDateString(),
                    'time' => $event->event_time,
                    'place' => $event->place,
                    'annual' => $event->is_annual,
                    'is_past' => ! $event->is_annual && $nextDate->lt($today),
                    'person_id' => $event->person_id ? (string) $event->person_id : null,
                    'person_name' => $event->person?->full_name,
                ];
            })
            ->sortBy(fn (array $event): string => $event['date'].' '.($event['time'] ?? ''))
            ->values();

        return response()->json([
            'upcoming' => $events->where('is_past', false)->values(),
            'archive' => $events->where('is_past', true)->sortByDesc('date')->values(),
        ]);
    }

    private function personThumbnail(Person $person): string
    {
        if ($person->thumbnail_url) {
            return $person->thumbnail_url;
        }

        return asset(match ($person->gender) {
            'male' => 'images/person-placeholder-male.svg',
            'female' => 'images/person-placeholder-female.svg',
            default => 'images/person-placeholder.svg',
        });
    }
}
