@extends('public.layout')

@section('title', __('public.auth.forgot_title'))
@section('robots', 'noindex,follow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ __('public.auth.forgot_heading') }}</h1>
    <p>{{ __('public.auth.forgot_text') }}</p>
    @if(session('status'))<p class="status-message" role="status">{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('password.email') }}" class="form-grid">
        @csrf
        <label class="wide">
            <span>{{ __('public.common.email') }}</span>
            <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required autofocus>
        </label>
        @error('email')<p class="wide field-error" role="alert">{{ $message }}</p>@enderror
        <div class="wide form-actions"><button class="button" type="submit">{{ __('public.auth.send_link') }}</button></div>
    </form>
</section>
@endsection
