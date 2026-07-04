@extends('public.layout')
@section('title', __('public.errors.405_title').' — '.__('public.brand'))
@section('robots', 'noindex,nofollow')
@section('content')
<section class="error-page">
    <article class="error-card">
        <div class="error-card__icon" aria-hidden="true">↪</div>
        <h1>{{ __('public.errors.405_title') }}</h1>
        <p>{{ __('public.errors.405_text') }}</p>
        <a class="button" href="{{ route('home') }}">{{ __('public.common.home') }}</a>
    </article>
</section>
@endsection
