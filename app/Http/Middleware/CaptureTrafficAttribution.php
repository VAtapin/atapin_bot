<?php

namespace App\Http\Middleware;

use App\Models\TrafficAttribution;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureTrafficAttribution
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->hasSession()
            && $request->isMethod('GET')
            && $request->cookie('analytics_consent') === 'granted'
            && ! $request->is('admin*', 'manage*', 'api/*', 'analytics/*', 'media/*')
        ) {
            $this->capture($request);
        }

        return $next($request);
    }

    private function capture(Request $request): void
    {
        try {
            $visitorId = (string) ($request->session()->get('analytics_visitor_id') ?: Str::uuid());
            $request->session()->put('analytics_visitor_id', $visitorId);
            $utm = collect(['source', 'medium', 'campaign', 'content', 'term'])
                ->mapWithKeys(fn (string $key): array => [$key => $this->clean($request->query("utm_{$key}"))]);
            $landing = $this->safeUrl($request->url());
            $referrer = $this->externalReferrer($request);

            $attribution = TrafficAttribution::query()->firstOrNew(['visitor_id' => $visitorId]);
            $isFirstTouch = ! $attribution->exists;
            $hasCampaignTouch = $utm->filter()->isNotEmpty() || $referrer !== null;
            if (! $attribution->exists) {
                $attribution->fill([
                    'user_id' => $request->user()?->id,
                    'utm_source' => $utm['source'],
                    'utm_medium' => $utm['medium'],
                    'utm_campaign' => $utm['campaign'],
                    'utm_content' => $utm['content'],
                    'utm_term' => $utm['term'],
                    'referrer' => $referrer,
                    'landing_page' => $landing,
                    'first_seen_at' => now(),
                ]);
            }
            $lastTouch = $isFirstTouch || $hasCampaignTouch
                ? [
                    'last_utm_source' => $utm['source'],
                    'last_utm_medium' => $utm['medium'],
                    'last_utm_campaign' => $utm['campaign'],
                    'last_utm_content' => $utm['content'],
                    'last_utm_term' => $utm['term'],
                    'last_referrer' => $referrer,
                    'last_landing_page' => $landing,
                ]
                : [];
            $attribution->fill([
                'user_id' => $attribution->user_id ?: $request->user()?->id,
                'last_seen_at' => now(),
                ...$lastTouch,
            ])->save();
        } catch (Throwable) {
            // Attribution is optional and must not break a page.
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    private function safeUrl(string $url): ?string
    {
        if ($url === '') {
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
        $url = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }
        $url .= $parts['path'] ?? '/';

        return mb_substr($url, 0, 2000);
    }

    private function externalReferrer(Request $request): ?string
    {
        $referrer = $this->safeUrl((string) $request->headers->get('referer'));
        if (! $referrer) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        return $host && ! hash_equals(mb_strtolower($request->getHost()), mb_strtolower((string) $host))
            ? $referrer
            : null;
    }
}
