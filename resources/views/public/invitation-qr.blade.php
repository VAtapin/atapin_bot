<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('public.invitation.title', ['tree' => $invitation->tree->name]) }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @vite('resources/css/public.css')
</head>
<body class="invitation-page">
<main class="invitation-card" data-invitation-url="{{ $url }}">
    <h1>{{ $invitation->tree->name }}</h1>
    <p>{{ $invitation->label ?: __('public.invitation.default') }}</p>
    <canvas id="qr"></canvas>
    <p class="invitation-url">{{ $url }}</p>
    <button class="button print-hidden" type="button" data-print>{{ __('public.invitation.print') }}</button>
</main>
@vite('resources/js/invitation-qr.js')
</body>
</html>
