@extends('public.layout')

@section('title', $page->meta_title ?: $page->title.' — '.__('public.brand'))
@section('description', $page->meta_description ?: __('public.cms.fallback_description'))
@section('analytics_event', 'view_cms_page')
@section('og_type', 'article')
@if($page->og_image_path)
    @section('image', url(\Illuminate\Support\Facades\Storage::disk('public')->url($page->og_image_path)))
@endif

@push('head')
@php
    $articleSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $page->title,
        'description' => $page->meta_description ?: __('public.cms.fallback_description'),
        'dateModified' => $page->updated_at?->toAtomString(),
        'inLanguage' => $page->locale,
        'mainEntityOfPage' => url()->current(),
    ];
@endphp
<script type="application/ld+json">{!! json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endpush

@section('content')
<article class="content-card">
    <h1>{{ $page->title }}</h1>
    <div class="cms-content">{!! $page->content !!}</div>
</article>
@endsection
