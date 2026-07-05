@extends('public.layout')

@php
    $homeTranslation = $homePage?->translation();
    $homeOgImage = $homeTranslation?->og_image_path
        ? \Illuminate\Support\Facades\Storage::disk('public')->url($homeTranslation->og_image_path)
        : asset('images/og-image.jpg');
    try {
        $registrationEnabled = \App\Models\PlatformSetting::value('registration_enabled', true);
    } catch (\Throwable) {
        $registrationEnabled = false;
    }
@endphp

@section('title', $homeTranslation?->meta_title ?: __('public.meta.home_title'))
@section('description', $homeTranslation?->meta_description ?: __('public.meta.home_description'))
@section('image', $homeOgImage)
@section('image_alt', $homeTranslation?->og_image_alt ?: __('public.meta.image_alt'))
@section('analytics_event', 'view_home')
@section('body_class', 'public-home')

@section('content')
    @if($homePage && $homePage->sections->isNotEmpty())
        <div class="home-builder">
            @foreach($homePage->sections as $section)
                @php($translation = $section->translation())
                @continue(! $translation)
                @includeIf('public.home.sections.'.$section->type, compact(
                    'section',
                    'translation',
                    'plans',
                    'homepage',
                    'registrationEnabled',
                ))
            @endforeach
        </div>
    @else
        <section class="public-hero public-wrap">
            <p class="eyebrow">🌿 {{ __('public.home.eyebrow') }}</p>
            <h1>{{ __('public.home.title') }}</h1>
            <p class="public-hero__lead">{{ __('public.home.lead') }}</p>
            @if($registrationEnabled)
                <div class="hero-actions">
                    <a class="button" href="{{ route('register') }}">{{ __('public.home.create') }}</a>
                </div>
            @endif
            <p class="hero-join-note">
                <strong>{{ __('public.auth.join_existing_title') }}</strong>
                {{ __('public.auth.join_existing_text') }}
            </p>
        </section>
    @endif
@endsection
