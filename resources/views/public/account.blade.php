@extends('public.layout')

@section('title', __('public.account.title'))
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card">
    <h1>{{ __('public.account.heading') }}</h1>

    @if(request()->boolean('welcome'))
        <article class="notice-card notice-card--accent">
            <strong>{{ __('public.account.welcome_title') }}</strong>
            <p>{{ __('public.account.welcome_text') }}</p>
            <div class="inline-actions">
                <a class="button" href="{{ route('totp.setup') }}">{{ __('public.account.connect_now') }}</a>
                <a class="button secondary" href="{{ route('trees.choose') }}">{{ __('public.account.later_tree') }}</a>
            </div>
        </article>
    @endif

    <p>{{ __('public.account.identities_text') }}</p>
    @if(session('status'))<p class="status-message" role="status">{{ session('status') }}</p>@endif

    <div class="card-stack">
        <article class="identity-card">
            <div><strong>{{ __('public.account.email_password') }}</strong><br><small>{{ $user->email }}</small></div>
        </article>
        @foreach($user->externalIdentities as $identity)
            <article class="identity-card">
                <div>
                    <strong>{{ ucfirst($identity->provider) }}</strong><br>
                    <small>
                        {{ $identity->username ? '@'.$identity->username : $identity->provider_user_id }}
                        · {{ __('public.account.last_login', ['date' => $identity->last_login_at?->format('d.m.Y H:i') ?: __('public.account.unknown')]) }}
                    </small>
                </div>
                <form method="post" action="{{ route('account.identities.unlink', $identity) }}">
                    @csrf
                    @method('delete')
                    <button class="button secondary" type="submit">{{ __('public.account.disconnect') }}</button>
                </form>
            </article>
        @endforeach
    </div>

    @unless($user->externalIdentities->contains('provider', 'telegram'))
        <form method="post" action="{{ route('account.telegram.connect') }}">
            @csrf
            <p><button class="button" type="submit">{{ __('public.account.connect_telegram') }}</button></p>
        </form>
    @endunless

    <article class="settings-card">
        <strong>{{ __('public.account.totp') }}</strong>
        @if($user->two_factor_confirmed_at)
            <p class="success">{{ __('public.account.connected', ['date' => $user->two_factor_confirmed_at->format('d.m.Y H:i')]) }}</p>
            @if($user->two_factor_required)
                <p><strong>{{ __('public.account.required') }}</strong></p>
            @endif
            <p><a class="button secondary" href="{{ route('totp.setup') }}">{{ __('public.account.reconnect') }}</a></p>
            @unless($user->is_super_admin || $user->two_factor_required)
                <form class="compact-form" method="post" action="{{ route('totp.destroy') }}">
                    @csrf
                    @method('delete')
                    <label><span>{{ __('public.account.disable_code') }}</span><input name="code" inputmode="numeric" maxlength="6" required></label>
                    <button class="button secondary" type="submit">{{ __('public.account.disable') }}</button>
                </form>
            @endunless
        @else
            <p>{{ __('public.account.totp_text') }}</p>
            @if($user->two_factor_required)
                <p><strong>{{ __('public.account.admin_required') }}</strong></p>
            @endif
            <a class="button" href="{{ route('totp.setup') }}">{{ __('public.account.connect_app') }}</a>
        @endif
    </article>

    <p class="muted">{{ __('public.account.future_providers') }}</p>
</section>
@endsection
