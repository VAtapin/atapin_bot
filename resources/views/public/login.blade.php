@extends('public.layout')

@section('title', __('public.auth.login_title'))
@section('robots', 'noindex,follow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ $tree ? __('public.auth.login_tree', ['tree' => $tree->name]) : __('public.auth.login_archive') }}</h1>
    <p>{{ $tree ? __('public.auth.login_tree_hint') : __('public.auth.login_hint') }}</p>

    @if ($errors->any())
        <p class="error" role="alert">{{ $errors->first() }}</p>
    @endif

    <form method="post" action="{{ route('login.store') }}" class="form-grid">
        @csrf
        @if ($tree)
            <input type="hidden" name="tree_slug" value="{{ $tree->slug }}">
        @endif
        @if(request('return'))
            <input type="hidden" name="return_to" value="{{ request('return') }}">
        @endif
        <label class="wide">
            <span>{{ __('public.auth.login_field') }}</span>
            <input name="login" value="{{ old('login') }}" autocomplete="username" required autofocus>
        </label>
        <label class="wide">
            <span>{{ __('public.common.password') }}</span>
            <input name="password" type="password" autocomplete="current-password" required>
            <small><a href="{{ route('password.request') }}">{{ __('public.auth.forgot') }}</a></small>
        </label>
        <label class="wide check-field">
            <input name="remember" type="checkbox" value="1">
            <span>{{ __('public.auth.remember') }}</span>
        </label>
        <div class="wide form-actions">
            <button class="button" type="submit">{{ __('public.auth.submit') }}</button>
            @if(config('services.telegram.oidc_client_id'))
                <a class="button secondary" href="{{ route('telegram.login', array_filter([
                    'tree' => $tree?->slug,
                    'return' => request('return'),
                ])) }}">{{ __('public.auth.telegram') }}</a>
            @endif
        </div>
    </form>
</section>
@endsection
