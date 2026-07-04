@extends('public.layout')
@section('title', __('public.errors.403_title').' — '.__('public.brand'))
@section('robots', 'noindex,nofollow')
@section('content')
<section class="error-page">
    <article class="error-card">
        <div class="error-card__icon" aria-hidden="true">🔒</div>
        <h1>{{ __('public.errors.403_title') }}</h1>
        <p>{{ $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.' ? $exception->getMessage() : __('public.errors.403_text') }}</p>
        <div class="error-card__actions">
            <button class="button secondary" type="button" data-history-back>{{ __('public.errors.back') }}</button>
            @auth<a class="button" href="{{ route('trees.choose') }}">{{ __('public.nav.trees') }}</a>@endauth
        </div>
    </article>
</section>
@endsection
