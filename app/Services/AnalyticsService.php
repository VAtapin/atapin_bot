<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\FamilyTree;
use App\Models\TrafficAttribution;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class AnalyticsService
{
    /** @var list<string> */
    public const FRONTEND_EVENTS = [
        'view_home',
        'view_family_tree_landing',
        'cta_register_click',
        'view_pricing',
        'view_faq',
        'view_cms_page',
        'view_plan',
    ];

    /** @var list<string> */
    private const FORBIDDEN_PARAMETER_PARTS = [
        'name',
        'email',
        'phone',
        'birth',
        'photo',
        'biography',
        'address',
        'password',
        'token',
    ];

    public function record(
        string $event,
        ?Request $request = null,
        ?User $user = null,
        ?FamilyTree $tree = null,
        array $parameters = [],
        ?string $deduplicationKey = null,
        bool $queueForBrowser = false,
    ): ?AnalyticsEvent {
        try {
            $request ??= app()->runningInConsole() ? null : request();
            $user ??= $request?->user();
            $safeParameters = $this->sanitizeParameters($parameters);
            $attribution = $this->attribution($request, $user, $safeParameters);
            $platform = $this->platform($request);
            $values = [
                'event_uuid' => (string) Str::uuid(),
                'event_name' => mb_substr($event, 0, 80),
                'visitor_id' => $attribution?->visitor_id,
                'user_id' => $user?->id,
                'tree_id' => $tree?->id ?? Arr::get($safeParameters, 'tree_id'),
                'plan_id' => Arr::get($safeParameters, 'plan_id'),
                'platform' => $platform,
                'landing_page' => $attribution?->landing_page ?: $this->safeUrl($request?->url()),
                'referrer' => $attribution?->referrer ?: $this->safeUrl($request?->headers->get('referer')),
                'utm_source' => $attribution?->utm_source,
                'utm_medium' => $attribution?->utm_medium,
                'utm_campaign' => $attribution?->utm_campaign,
                'utm_content' => $attribution?->utm_content,
                'utm_term' => $attribution?->utm_term,
                'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
                'ip_hash' => $request?->ip() ? $this->hashIp($request->ip()) : null,
                'value' => Arr::get($safeParameters, 'value'),
                'currency' => Arr::get($safeParameters, 'currency'),
                'parameters' => $safeParameters ?: null,
                'external_pending' => $queueForBrowser && (bool) $user,
                'occurred_at' => now(),
            ];

            $analyticsEvent = $deduplicationKey
                ? AnalyticsEvent::query()->firstOrCreate(
                    ['deduplication_key' => mb_substr($deduplicationKey, 0, 190)],
                    $values,
                )
                : AnalyticsEvent::query()->create($values);

            return $analyticsEvent;
        } catch (Throwable) {
            return null;
        }
    }

    public function linkUser(Request $request, User $user): void
    {
        try {
            TrafficAttribution::query()
                ->where('visitor_id', $request->session()->get('analytics_visitor_id'))
                ->update(['user_id' => $user->id]);
        } catch (Throwable) {
            // Analytics must never block registration.
        }
    }

    public function hashIp(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }

    private function attribution(?Request $request, ?User $user, array $parameters = []): ?TrafficAttribution
    {
        if (! $request?->hasSession()) {
            return $user?->id
                ? TrafficAttribution::query()->where('user_id', $user->id)->latest('last_seen_at')->first()
                : null;
        }

        $visitorId = $request->session()->get('analytics_visitor_id');

        $attribution = $visitorId
            ? TrafficAttribution::query()->where('visitor_id', $visitorId)->first()
            : null;

        if (
            ! $attribution
            && $request->cookie('analytics_consent') === 'granted'
            && ! empty($parameters['landing_page'])
        ) {
            $visitorId = (string) ($visitorId ?: Str::uuid());
            $request->session()->put('analytics_visitor_id', $visitorId);
            $referrer = $this->safeUrl($parameters['referrer'] ?? null);
            if ($referrer && mb_strtolower((string) parse_url($referrer, PHP_URL_HOST)) === mb_strtolower($request->getHost())) {
                $referrer = null;
            }
            $attribution = TrafficAttribution::query()->create([
                'visitor_id' => $visitorId,
                'user_id' => $user?->id,
                'utm_source' => $parameters['utm_source'] ?? null,
                'utm_medium' => $parameters['utm_medium'] ?? null,
                'utm_campaign' => $parameters['utm_campaign'] ?? null,
                'utm_content' => $parameters['utm_content'] ?? null,
                'utm_term' => $parameters['utm_term'] ?? null,
                'referrer' => $referrer,
                'landing_page' => $this->safeUrl($parameters['landing_page']),
                'first_seen_at' => now(),
                'last_utm_source' => $parameters['utm_source'] ?? null,
                'last_utm_medium' => $parameters['utm_medium'] ?? null,
                'last_utm_campaign' => $parameters['utm_campaign'] ?? null,
                'last_utm_content' => $parameters['utm_content'] ?? null,
                'last_utm_term' => $parameters['utm_term'] ?? null,
                'last_referrer' => $referrer,
                'last_landing_page' => $this->safeUrl($parameters['landing_page']),
                'last_seen_at' => now(),
            ]);
        }

        return $attribution;
    }

    private function platform(?Request $request): string
    {
        $requested = mb_strtolower((string) ($request?->query('platform') ?: $request?->header('X-App-Platform')));
        if (in_array($requested, ['telegram', 'vk', 'ok', 'max', 'web'], true)) {
            return $requested;
        }
        if ($request?->header('X-Telegram-Init-Data')) {
            return 'telegram';
        }
        if ($request?->header('X-VK-Launch-Params')) {
            return 'vk';
        }
        if ($request?->is('api/telegram*', 'auth/telegram*')) {
            return 'telegram';
        }

        return 'web';
    }

    private function sanitizeParameters(array $parameters): array
    {
        $safe = [];
        foreach (array_slice($parameters, 0, 30, true) as $key => $value) {
            $normalizedKey = Str::snake((string) $key);
            if ($normalizedKey === '' || ($normalizedKey !== 'plan_name' && collect(self::FORBIDDEN_PARAMETER_PARTS)->contains(
                fn (string $part): bool => str_contains($normalizedKey, $part),
            ))) {
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$normalizedKey] = $value;
            } elseif (is_string($value)) {
                $safe[$normalizedKey] = mb_substr($value, 0, 250);
            }
        }

        return $safe;
    }

    private function safeUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $url = (string) preg_replace(
            ['~/invite/[a-f0-9]{32,}~i', '~/reset-password/[^/?]+~i', '~/auth/telegram/link/[^/?]+~i'],
            ['/invite/[redacted]', '/reset-password/[redacted]', '/auth/telegram/link/[redacted]'],
            $url,
        );
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $safe = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $safe .= ':'.$parts['port'];
        }
        $safe .= $parts['path'] ?? '/';

        return mb_substr($safe, 0, 2000);
    }
}
