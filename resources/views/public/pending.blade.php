@extends('public.layout')

@section('title', __('public.auth.pending_title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ __('public.auth.pending_heading') }}</h1>
    <p>{{ $tree
        ? __('public.auth.pending_tree', ['tree' => $tree->name])
        : __('public.auth.pending_default') }}</p>
    <a class="button secondary" href="{{ route('home') }}">{{ __('public.common.home') }}</a>
</section>
@endsection
