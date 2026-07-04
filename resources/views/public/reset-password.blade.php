@extends('public.layout')

@section('title', __('public.auth.reset_title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ __('public.auth.reset_heading') }}</h1>
    <form method="post" action="{{ route('password.update') }}" class="form-grid">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="wide"><span>{{ __('public.common.email') }}</span><input name="email" type="email" value="{{ old('email', $email) }}" autocomplete="email" required></label>
        <label><span>{{ __('public.auth.new_password') }}</span><input name="password" type="password" autocomplete="new-password" required></label>
        <label><span>{{ __('public.common.password_confirmation') }}</span><input name="password_confirmation" type="password" autocomplete="new-password" required></label>
        @if($errors->any())<p class="wide field-error" role="alert">{{ $errors->first() }}</p>@endif
        <div class="wide form-actions"><button class="button" type="submit">{{ __('public.auth.save_password') }}</button></div>
    </form>
</section>
@endsection
