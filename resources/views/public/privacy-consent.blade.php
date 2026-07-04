@extends('public.layout')

@section('title', __('public.privacy.title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card content-card--narrow">
    <h1>{{ __('public.privacy.heading') }}</h1>
    <p>{{ __('public.privacy.text') }}</p>
    <p><a href="{{ route('public.page', ['slug' => 'datenschutz']) }}" target="_blank" rel="noopener">{{ __('public.privacy.read') }}</a></p>
    @if($errors->any())<p class="error" role="alert">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('privacy-consent.store') }}" class="form-grid">
        @csrf
        <label class="wide check-field">
            <input name="privacy_consent" type="checkbox" value="1" required>
            <span>{{ __('public.privacy.checkbox') }}</span>
        </label>
        <div class="wide form-actions"><button class="button" type="submit">{{ __('public.privacy.accept') }}</button></div>
    </form>
</section>
@endsection
