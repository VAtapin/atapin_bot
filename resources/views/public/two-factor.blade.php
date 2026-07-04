@extends('public.layout')

@section('title', __('public.auth.two_factor_title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ __('public.auth.two_factor_heading') }}</h1>
    <p>{{ auth()->user()?->two_factor_confirmed_at
        ? __('public.auth.two_factor_app')
        : __('public.auth.two_factor_sent') }}</p>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('two-factor.verify') }}" class="form-grid">
        @csrf
        <label class="wide"><span>{{ __('public.auth.code') }}</span><input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label>
        <div class="wide form-actions"><button class="button" type="submit">{{ __('public.common.continue') }}</button></div>
    </form>
</section>
@endsection
