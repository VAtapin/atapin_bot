@extends('public.layout')

@section('title', __('public.meta.faq_title'))
@section('description', __('public.meta.faq_description'))
@section('analytics_event', 'view_faq')

@push('head')
@php
    $faqSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $categories->flatMap->items->map(fn ($item): array => [
            '@type' => 'Question',
            'name' => $item->question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => trim(strip_tags($item->answer)),
            ],
        ])->values()->all(),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<div class="faq-page">
    <section class="faq-hero">
        <span class="faq-kicker">{{ __('public.faq.kicker') }}</span>
        <h1>{{ __('public.faq.heading') }}</h1>
        <p>{{ __('public.faq.lead') }}</p>
        <label class="faq-search">
            <span aria-hidden="true">⌕</span>
            <input type="search" data-faq-search placeholder="{{ __('public.faq.search') }}" autocomplete="off">
        </label>
    </section>

    <nav class="faq-categories" aria-label="{{ __('public.faq.categories') }}">
        @foreach($categories as $category)
            <a href="#{{ $category->slug }}">{{ $category->title }}</a>
        @endforeach
    </nav>

    <p class="faq-empty" data-faq-empty hidden>{{ __('public.faq.empty') }}</p>

    @foreach($categories as $category)
        <section class="faq-section" id="{{ $category->slug }}" data-faq-section>
            <header>
                <h2>{{ $category->title }}</h2>
                @if($category->description)<p>{{ $category->description }}</p>@endif
            </header>
            <div class="faq-items">
                @foreach($category->items as $item)
                    <details class="faq-item"
                             data-faq-item
                             data-search="{{ mb_strtolower($item->question.' '.$item->keywords.' '.strip_tags($item->answer)) }}">
                        <summary>{{ $item->question }} <span aria-hidden="true">＋</span></summary>
                        <div class="faq-answer cms-content">{!! $item->answer !!}</div>
                    </details>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
@endsection
