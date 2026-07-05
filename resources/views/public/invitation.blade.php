@extends('public.layout')

@section('title', __('public.invitation.title', ['tree' => $invitation->tree->name]))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card auth-card invitation-card">
    <p class="eyebrow">{{ __('public.invitation.eyebrow') }}</p>
    <h1>{{ $invitation->tree->name }}</h1>
    <p>{{ __('public.invitation.text') }}</p>

    @if($invitation->person)
        <p class="invitation-person">
            {{ __('public.invitation.person', ['person' => $invitation->person->full_name]) }}
        </p>
    @endif

    @if($telegramLoginUrl)
        <a class="button button--wide" href="{{ $telegramLoginUrl }}">
            {{ __('public.invitation.telegram') }}
        </a>
        <p class="form-divider"><span>{{ __('miniapp.auth.or') }}</span></p>
    @endif

    <h2>{{ __('public.invitation.create_heading') }}</h2>
    <p>{{ __('public.invitation.create_text') }}</p>
    <form class="form-grid" method="post" action="{{ route('tree.invitation.store', $token) }}">
        @csrf
        <label class="@error('name') field-invalid @enderror">
            <span>{{ __('public.auth.name') }}</span>
            <input name="name" value="{{ old('name') }}" autocomplete="name" required autofocus>
            @error('name')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="@error('email') field-invalid @enderror">
            <span>{{ __('public.common.email') }}</span>
            <input name="email" type="email" value="{{ old('email') }}" autocomplete="email" required>
            @error('email')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label class="@error('password') field-invalid @enderror">
            <span>{{ __('public.common.password') }}</span>
            <input name="password" type="password" autocomplete="new-password" required>
            <small>{{ __('public.auth.password_hint') }}</small>
            @error('password')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <label>
            <span>{{ __('public.common.password_confirmation') }}</span>
            <input name="password_confirmation" type="password" autocomplete="new-password" required>
        </label>
        <label class="wide check-field @error('privacy_consent') field-invalid @enderror">
            <input name="privacy_consent" type="checkbox" value="1" required @checked(old('privacy_consent'))>
            <span>
                {!! __('public.auth.privacy_consent', [
                    'privacy' => '<a href="'.e(route('public.page', ['slug' => 'datenschutz'])).'" target="_blank" rel="noopener">'.e(__('public.auth.privacy_link')).'</a>',
                ]) !!}
            </span>
            @error('privacy_consent')<small class="field-error">{{ $message }}</small>@enderror
        </label>
        <div class="wide form-actions">
            <button class="button" type="submit">{{ __('public.invitation.accept') }}</button>
        </div>
    </form>

    <p class="auth-note">
        {{ __('public.invitation.existing') }}
        <a href="{{ route('login', ['tree' => $invitation->tree->slug]) }}">{{ __('public.nav.login') }}</a>
    </p>
</section>
@endsection
