<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#68734b">
    <meta name="application-name" content="{{ __('public.brand') }}">

    @include('public.partials.seo')

    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/apple-touch-icon.png') }}">

    @vite('resources/js/public.js')
    @stack('head')
</head>
<body class="@yield('body_class')" data-analytics-event="@yield('analytics_event')">
<a class="skip-link" href="#main-content">{{ __('public.common.continue') }}</a>
@include('public.partials.header')

<main id="main-content" class="public-main">
    @yield('content')
</main>

@include('public.partials.footer')
@include('public.partials.analytics')
@stack('scripts')
</body>
</html>
