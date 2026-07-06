@php
    use App\Support\PublicSeo;

    $locale = app()->getLocale();
    $seoTitle = trim($__env->yieldContent('title')) ?: __('public.meta.default_title');
    $seoDescription = trim($__env->yieldContent('description')) ?: __('public.meta.default_description');
    $seoImage = trim($__env->yieldContent('image')) ?: asset('images/og-image.jpg');
    $canonicalUrl = trim($__env->yieldContent('canonical')) ?: PublicSeo::canonical(request());
    $seoRobots = trim($__env->yieldContent('robots')) ?: 'index,follow,max-image-preview:large';
    try {
        $googleSiteVerification = \App\Models\PlatformSetting::value('google_site_verification');
        $yandexSiteVerification = \App\Models\PlatformSetting::value('yandex_site_verification');
    } catch (\Throwable) {
        $googleSiteVerification = null;
        $yandexSiteVerification = null;
    }
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => __('public.brand'),
        'url' => url('/'),
        'description' => $seoDescription,
        'inLanguage' => $locale,
        'publisher' => [
            '@type' => 'Organization',
            'name' => __('public.brand'),
            'url' => url('/'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('images/logo.png'),
            ],
        ],
    ];
@endphp

<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<meta name="robots" content="{{ $seoRobots }}">
@if(filled($googleSiteVerification))
<meta name="google-site-verification" content="{{ $googleSiteVerification }}">
@endif
@if(filled($yandexSiteVerification))
<meta name="yandex-verification" content="{{ $yandexSiteVerification }}">
@endif
<link rel="canonical" href="{{ $canonicalUrl }}">

@foreach(PublicSeo::LOCALES as $alternateLocale)
    <link rel="alternate" hreflang="{{ $alternateLocale }}" href="{{ PublicSeo::localizedUrl($alternateLocale) }}">
@endforeach
<link rel="alternate" hreflang="x-default" href="{{ PublicSeo::localizedUrl('ru') }}">

<meta property="og:locale" content="{{ PublicSeo::ogLocale($locale) }}">
@foreach(array_diff(PublicSeo::LOCALES, [$locale]) as $alternateLocale)
    <meta property="og:locale:alternate" content="{{ PublicSeo::ogLocale($alternateLocale) }}">
@endforeach
<meta property="og:type" content="@yield('og_type', 'website')">
<meta property="og:site_name" content="{{ __('public.brand') }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:image" content="{{ $seoImage }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="@yield('image_alt', __('public.meta.image_alt'))">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoImage }}">
<meta name="twitter:image:alt" content="@yield('image_alt', __('public.meta.image_alt'))">

<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
