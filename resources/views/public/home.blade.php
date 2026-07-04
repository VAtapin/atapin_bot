@extends('public.layout')

@section('title', __('public.meta.home_title'))
@section('description', __('public.meta.home_description'))
@section('analytics_event', 'view_home')

@section('content')
@php
    try {
        $registrationEnabled = \App\Models\PlatformSetting::value('registration_enabled', true);
    } catch (\Throwable) {
        $registrationEnabled = false;
    }
@endphp
<section class="public-hero public-wrap">
    <p class="eyebrow">🌿 {{ __('public.home.eyebrow') }}</p>
    <h1>{{ __('public.home.title') }}</h1>
    <p class="public-hero__lead">{{ __('public.home.lead') }}</p>

    <div class="hero-actions">
        @if($registrationEnabled)
            <a class="button" data-analytics-click="cta_register_click" href="{{ route('register') }}">{{ __('public.home.create') }}</a>
        @endif
        <a class="button secondary" href="#how-it-works">{{ __('public.home.how_link') }}</a>
    </div>

    <div class="trust-row" aria-label="{{ __('public.home.trust_label') }}">
        @foreach(__('public.home.trust') as $item)
            <span>{{ $item }}</span>
        @endforeach
    </div>
</section>

<section class="public-section public-wrap">
    <div class="story-panel">
        <p>{{ __('public.home.story') }}</p>
    </div>
</section>

<section class="public-section public-wrap">
    <h2>{{ __('public.home.features_title') }}</h2>
    <p class="section-lead">{{ __('public.home.features_lead') }}</p>
    <div class="feature-grid">
        @foreach(__('public.home.features') as $feature)
            <article class="feature-card">
                <span class="feature-card__icon" aria-hidden="true">{{ $feature['icon'] }}</span>
                <h3>{{ $feature['title'] }}</h3>
                <p>{{ $feature['text'] }}</p>
            </article>
        @endforeach
    </div>
</section>

<section class="public-section public-wrap" id="how-it-works">
    <h2>{{ __('public.home.how_title') }}</h2>
    <p class="section-lead">{{ __('public.home.how_lead') }}</p>
    <div class="step-grid">
        @foreach(__('public.home.steps') as $step)
            <article class="step-card">
                <h3>{{ $step['title'] }}</h3>
                <p>{{ $step['text'] }}</p>
            </article>
        @endforeach
    </div>
</section>

<section class="public-section public-wrap">
    <div class="privacy-panel">
        <h2>{{ __('public.home.privacy_title') }}</h2>
        <p>{{ __('public.home.privacy_text') }}</p>
    </div>
</section>

@if($plans->isNotEmpty())
    <section class="public-section public-wrap" data-analytics-view="view_pricing">
        <h2>{{ __('public.home.plans_title') }}</h2>
        <p class="section-lead">{{ __('public.home.plans_lead') }}</p>
        <div class="plan-grid">
            @foreach($plans as $plan)
                <article class="plan-card">
                    <h3>{{ \Illuminate\Support\Facades\Lang::has("public.plans.{$plan->code}.name")
                        ? __("public.plans.{$plan->code}.name")
                        : $plan->name }}</h3>
                    <p>{{ \Illuminate\Support\Facades\Lang::has("public.plans.{$plan->code}.description")
                        ? __("public.plans.{$plan->code}.description")
                        : $plan->description }}</p>
                    <strong class="plan-card__price">
                        {{ $plan->price_monthly > 0
                            ? __('public.home.per_month', ['price' => $plan->price_monthly, 'currency' => $plan->currency])
                            : __('public.home.free') }}
                    </strong>
                    <p class="plan-card__limits">
                        {{ __('public.home.plan_limits', [
                            'people' => number_format($plan->people_limit, 0, ',', ' '),
                            'storage' => round($plan->storage_limit_bytes / 1073741824, 1),
                        ]) }}
                    </p>
                    @if($registrationEnabled)
                        <a class="button" data-analytics-click="cta_register_click" href="{{ route('register') }}">{{ __('public.home.start') }}</a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif

<section class="public-section public-wrap">
    <h2>{{ __('public.home.questions_title') }}</h2>
    <p class="section-lead">{{ __('public.home.questions_lead') }}</p>
    <div class="home-faq-grid">
        @foreach(__('public.home.questions') as $question)
            <article class="home-faq-card">
                <h3>{{ $question['title'] }}</h3>
                <p>{{ $question['text'] }}</p>
            </article>
        @endforeach
    </div>
    <div class="section-actions">
        <a class="button secondary" href="{{ route('faq') }}">{{ __('public.home.open_faq') }}</a>
    </div>
</section>

<section class="public-section public-wrap">
    <div class="final-cta">
        <h2>{{ __('public.home.cta_title') }}</h2>
        <p>{{ __('public.home.cta_text') }}</p>
        <div class="hero-actions">
            @if($registrationEnabled)
                <a class="button" data-analytics-click="cta_register_click" href="{{ route('register') }}">{{ __('public.home.create') }}</a>
            @endif
            <a class="button secondary" href="{{ route('public.page', 'about') }}">{{ __('public.nav.about') }}</a>
        </div>
    </div>
</section>
@endsection
