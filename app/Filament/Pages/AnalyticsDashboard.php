<?php

namespace App\Filament\Pages;

use App\Models\AnalyticsEvent;
use App\Models\Plan;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class AnalyticsDashboard extends Page
{
    private const VISIT_EVENTS = [
        'view_home',
        'view_family_tree_landing',
        'view_faq',
        'view_cms_page',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Аналитика';

    protected static ?string $navigationLabel = 'Обзор аналитики';

    protected static ?string $title = 'Аналитика платформы';

    protected static ?int $navigationSort = -20;

    protected string $view = 'filament.pages.analytics-dashboard';

    public string $period = '30';

    public string $utmSource = '';

    public string $utmMedium = '';

    public string $utmCampaign = '';

    public string $landingPage = '';

    public string $platform = '';

    public string $eventName = '';

    public string $trafficSource = '';

    public string $planId = '';

    public function mount(): void
    {
        $this->period = in_array((string) request('period'), ['7', '30', '90', '365'], true)
            ? (string) request('period')
            : '30';
        $this->utmSource = mb_substr((string) request('utm_source'), 0, 255);
        $this->utmMedium = mb_substr((string) request('utm_medium'), 0, 255);
        $this->utmCampaign = mb_substr((string) request('utm_campaign'), 0, 255);
        $this->landingPage = mb_substr((string) request('landing_page'), 0, 500);
        $this->platform = in_array((string) request('platform'), ['web', 'telegram', 'vk', 'ok', 'max'], true)
            ? (string) request('platform')
            : '';
        $this->eventName = mb_substr((string) request('event'), 0, 80);
        $this->trafficSource = in_array((string) request('traffic_source'), ['direct', 'referral', 'utm'], true)
            ? (string) request('traffic_source')
            : '';
        $this->planId = ctype_digit((string) request('plan_id')) ? (string) request('plan_id') : '';
    }

    public function getViewData(): array
    {
        $base = $this->query();
        $count = fn (string $event): int => (clone $base)->where('event_name', $event)->count();
        $visits = (clone $base)->whereIn('event_name', self::VISIT_EVENTS)->count();
        $registrations = $count('sign_up');
        $trees = $count('family_tree_created');
        $qualityTrees = $count('first_5_people_added');
        $purchases = $count('purchase');
        $revenue = (float) (clone $base)->where('event_name', 'purchase')->sum('value');
        $metrics = [
            'Посещения' => $visits,
            'Регистрации' => $registrations,
            'Создано деревьев' => $trees,
            'Добавлено людей' => $count('person_added'),
            'Деревьев с 5+ людьми' => $qualityTrees,
            'Загрузок фотографий' => $count('photo_uploaded'),
            'Приглашений отправлено' => $count('invite_sent'),
            'Приглашений принято' => $count('invite_accepted'),
            'Начато оплат' => $count('begin_checkout'),
            'Успешных оплат' => $purchases,
            'Неуспешных оплат' => $count('payment_failed'),
            'Выручка' => number_format($revenue, 2, ',', ' ').' (в валютах событий)',
        ];
        $conversions = [
            'Визит → регистрация' => $this->percent($registrations, $visits),
            'Регистрация → дерево' => $this->percent($trees, $registrations),
            'Дерево → 5+ людей' => $this->percent($qualityTrees, $trees),
            'Регистрация → оплата' => $this->percent($purchases, $registrations),
        ];
        $daily = (clone $base)
            ->selectRaw('DATE(occurred_at) as day, event_name, COUNT(*) as total, SUM(COALESCE(value, 0)) as revenue')
            ->whereIn('event_name', [...self::VISIT_EVENTS, 'sign_up', 'family_tree_created', 'purchase'])
            ->groupBy('day', 'event_name')
            ->orderBy('day')
            ->get()
            ->groupBy('day')
            ->map(fn ($rows, $day): array => [
                'day' => Carbon::parse($day)->format('d.m'),
                'visits' => (int) $rows->whereIn('event_name', self::VISIT_EVENTS)->sum('total'),
                'registrations' => (int) $rows->firstWhere('event_name', 'sign_up')?->total,
                'trees' => (int) $rows->firstWhere('event_name', 'family_tree_created')?->total,
                'payments' => (int) $rows->firstWhere('event_name', 'purchase')?->total,
                'revenue' => (float) $rows->where('event_name', 'purchase')->sum('revenue'),
            ])->values()->all();
        $campaigns = (clone $base)
            ->selectRaw("COALESCE(NULLIF(utm_source, ''), 'direct') as source, COALESCE(NULLIF(utm_campaign, ''), '—') as campaign, COUNT(*) as events")
            ->selectRaw("SUM(CASE WHEN event_name = 'sign_up' THEN 1 ELSE 0 END) as registrations")
            ->selectRaw("SUM(CASE WHEN event_name = 'first_5_people_added' THEN 1 ELSE 0 END) as quality")
            ->selectRaw("SUM(CASE WHEN event_name = 'purchase' THEN 1 ELSE 0 END) as purchases")
            ->selectRaw("SUM(CASE WHEN event_name = 'purchase' THEN COALESCE(value, 0) ELSE 0 END) as revenue")
            ->selectRaw("SUM(CASE WHEN event_name IN ('view_home', 'view_family_tree_landing', 'view_faq', 'view_cms_page') THEN 1 ELSE 0 END) as visits")
            ->groupBy('source', 'campaign')
            ->orderByDesc('registrations')
            ->limit(20)
            ->get();

        return [
            'metrics' => $metrics,
            'conversions' => $conversions,
            'daily' => $daily,
            'campaigns' => $campaigns,
            'latestEvents' => (clone $base)->with(['tree:id,name', 'plan:id,name'])->latest('occurred_at')->limit(20)->get(),
            'qualityUsers' => (clone $base)
                ->whereNotNull('user_id')
                ->whereIn('event_name', ['first_5_people_added', 'photo_uploaded', 'invite_sent', 'purchase'])
                ->select('user_id', DB::raw('COUNT(*) as actions'))
                ->groupBy('user_id')
                ->orderByDesc('actions')
                ->with('user:id,name')
                ->limit(15)
                ->get(),
            'activeTrees' => (clone $base)
                ->whereNotNull('tree_id')
                ->select('tree_id', DB::raw('COUNT(*) as actions'))
                ->groupBy('tree_id')
                ->orderByDesc('actions')
                ->with('tree:id,name')
                ->limit(15)
                ->get(),
            'utmSources' => AnalyticsEvent::query()->whereNotNull('utm_source')->distinct()->orderBy('utm_source')->pluck('utm_source'),
            'eventNames' => AnalyticsEvent::query()->distinct()->orderBy('event_name')->pluck('event_name'),
            'plans' => Plan::query()->orderBy('sort_order')->pluck('name', 'id'),
        ];
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    private function query(): Builder
    {
        return AnalyticsEvent::query()
            ->where('occurred_at', '>=', now()->subDays((int) $this->period))
            ->when($this->utmSource, fn (Builder $query) => $query->where('utm_source', $this->utmSource))
            ->when($this->utmMedium, fn (Builder $query) => $query->where('utm_medium', $this->utmMedium))
            ->when($this->utmCampaign, fn (Builder $query) => $query->where('utm_campaign', $this->utmCampaign))
            ->when($this->landingPage, fn (Builder $query) => $query->where('landing_page', 'like', "%{$this->landingPage}%"))
            ->when($this->platform, fn (Builder $query) => $query->where('platform', $this->platform))
            ->when($this->eventName, fn (Builder $query) => $query->where('event_name', $this->eventName))
            ->when($this->planId, fn (Builder $query) => $query->where('plan_id', $this->planId))
            ->when($this->trafficSource === 'direct', fn (Builder $query) => $query->whereNull('utm_source')->whereNull('referrer'))
            ->when($this->trafficSource === 'referral', fn (Builder $query) => $query->whereNull('utm_source')->whereNotNull('referrer'))
            ->when($this->trafficSource === 'utm', fn (Builder $query) => $query->whereNotNull('utm_source'));
    }

    private function percent(int $part, int $whole): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, 1, ',', ' ').'%' : '0%';
    }
}
