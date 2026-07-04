@php
    try {
        $publicRegistrationEnabled = \App\Models\PlatformSetting::value('registration_enabled', true);
    } catch (\Throwable) {
        $publicRegistrationEnabled = false;
    }
@endphp
<header class="site-header">
    <div class="public-wrap site-header__inner">
        <a class="site-logo" href="{{ route('home') }}" aria-label="{{ __('public.brand') }}">
            <img class="site-logo__image"
                 src="{{ asset('images/logo.png') }}"
                 width="44"
                 height="44"
                 alt=""
                 decoding="async">
            <span class="site-logo__text">
                <strong>{{ __('public.brand') }}</strong>
                <small>{{ __('public.tagline') }}</small>
            </span>
        </a>

        <button class="menu-toggle"
                type="button"
                data-menu-toggle
                data-open-label="{{ __('public.nav.menu') }}"
                data-close-label="{{ __('public.nav.close_menu') }}"
                aria-expanded="false"
                aria-controls="site-navigation"
                aria-label="{{ __('public.nav.menu') }}">
            <span></span>
        </button>

        <nav id="site-navigation" class="site-nav" data-site-nav aria-label="{{ __('public.nav.menu') }}">
            <a href="{{ route('public.page', ['slug' => 'about']) }}">{{ __('public.nav.about') }}</a>
            <a href="{{ route('public.page', ['slug' => 'contacts']) }}">{{ __('public.nav.contacts') }}</a>
            <a href="{{ route('faq') }}">{{ __('public.nav.help') }}</a>

            @auth
                <a class="button secondary" href="{{ route('trees.choose') }}">{{ __('public.nav.trees') }}</a>
                <a class="site-nav__link" href="{{ route('account') }}">{{ __('public.nav.security') }}</a>
                <form class="site-nav__logout" method="post" action="{{ route('logout') }}">
                    @csrf
                    <button class="button" type="submit">{{ __('public.nav.logout') }}</button>
                </form>
            @else
                <a class="button secondary" href="{{ route('login') }}">{{ __('public.nav.login') }}</a>
                @if($publicRegistrationEnabled)
                    <a class="button" href="{{ route('register') }}">{{ __('public.nav.register') }}</a>
                @endif
            @endauth

            <label class="site-nav__locale">
                <span class="skip-link">{{ __('public.language') }}</span>
                <select class="locale-select" data-locale-select aria-label="{{ __('public.language') }}">
                    @foreach(__('public.languages') as $code => $label)
                        <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </nav>
    </div>
</header>
