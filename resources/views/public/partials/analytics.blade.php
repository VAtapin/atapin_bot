@php
    try {
        $analyticsSettings = \App\Models\PlatformSetting::query()
            ->whereIn('key', ['analytics_ga4_id', 'analytics_yandex_id', 'analytics_vk_pixel_id'])
            ->get()
            ->keyBy('key');
        $analyticsProviders = [
            'ga4' => $analyticsSettings->get('analytics_ga4_id')?->value,
            'yandex' => $analyticsSettings->get('analytics_yandex_id')?->value,
            'vk' => $analyticsSettings->get('analytics_vk_pixel_id')?->value,
        ];
    } catch (\Throwable) {
        $analyticsProviders = ['ga4' => null, 'yandex' => null, 'vk' => null];
    }

    $analyticsConfig = [
        'endpoint' => route('analytics.event'),
        'pendingEndpoint' => auth()->check() ? route('analytics.pending') : null,
        'csrf' => csrf_token(),
        'consent' => request()->cookie('analytics_consent'),
        'providers' => $analyticsProviders,
    ];
@endphp
<script>window.publicAnalyticsConfig = @json($analyticsConfig);</script>
<aside class="consent-banner" data-consent-banner hidden aria-labelledby="consent-title">
    <div>
        <strong id="consent-title">{{ __('public.consent.title') }}</strong>
        <p>
            {{ __('public.consent.text') }}
            <a href="{{ route('public.page', ['slug' => 'datenschutz', 'lang' => app()->getLocale()]) }}">{{ __('public.consent.details') }}</a>
        </p>
    </div>
    <div class="consent-banner__actions">
        <button class="button secondary" type="button" data-consent-essential>{{ __('public.consent.essential') }}</button>
        <button class="button" type="button" data-consent-accept>{{ __('public.consent.accept') }}</button>
    </div>
</aside>
