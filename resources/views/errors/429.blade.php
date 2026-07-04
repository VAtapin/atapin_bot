@extends('public.layout')
@section('title', __('public.errors.429_title').' — '.__('public.brand'))
@section('robots', 'noindex,nofollow')
@section('content')
<section class="error-page">
    <article class="error-card">
        <div class="error-card__icon" aria-hidden="true">⏳</div>
        <h1>{{ __('public.errors.429_title') }}</h1>
        <p>{{ __('public.errors.429_text') }}</p>
        <button class="button" type="button" data-reload>{{ __('public.errors.retry') }}</button>
    </article>
</section>
@endsection
