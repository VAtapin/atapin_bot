@extends('public.layout')

@section('title', __('public.account.setup_title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ __('public.account.setup_heading') }}</h1>
    <p>{{ __('public.account.setup_text') }}</p>

    <div class="centered-media">
        <img src="{{ $qrCode }}" width="240" height="240" alt="{{ __('public.account.qr_alt') }}">
    </div>

    <details class="spaced-details">
        <summary>{{ __('public.account.manual') }}</summary>
        <p>{{ __('public.account.manual_text') }}</p>
        <code class="totp-code">{{ $secret }}</code>
    </details>

    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('totp.confirm') }}" class="form-grid">
        @csrf
        <label class="wide">
            <span>{{ __('public.account.app_code') }}</span>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        </label>
        <div class="wide form-actions"><button class="button" type="submit">{{ __('public.account.confirm') }}</button></div>
    </form>
</section>
@endsection
