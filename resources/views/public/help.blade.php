@extends('public.layout')

@section('title', __('public.help.title'))
@section('robots', 'noindex,nofollow')

@section('content')
<article class="content-card cms-content">
    <h1>{{ __('public.help.heading') }}</h1>
    <p><a class="button" href="{{ route('faq') }}">{{ __('public.help.open_faq') }}</a></p>
    @if($role === 'super_admin')
        <h2>{{ __('public.help.super_title') }}</h2>
        <p>{{ __('public.help.super_text') }}</p>
    @elseif($role === 'owner')
        <h2>{{ __('public.help.owner_title', ['tree' => $tree?->name]) }}</h2>
        <p>{{ __('public.help.owner_text') }}</p>
    @elseif($role === 'moderator')
        <h2>{{ __('public.help.moderator_title', ['tree' => $tree?->name]) }}</h2>
        <p>{{ __('public.help.moderator_text') }}</p>
    @else
        <h2>{{ __('public.help.member_title') }}</h2>
        <p>{{ __('public.help.member_text') }}</p>
    @endif
    <h2>{{ __('public.help.security_title') }}</h2>
    <p>{{ __('public.help.security_text') }}</p>
</article>
@endsection
