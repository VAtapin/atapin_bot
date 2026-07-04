<footer class="site-footer">
    <div class="public-wrap site-footer__inner">
        <div class="site-footer__brand">
            <strong>{{ __('public.brand') }}</strong>
            <span>{{ __('public.footer.privacy_note') }}</span>
        </div>
        <nav class="site-footer__links" aria-label="{{ __('public.nav.help') }}">
            <a href="{{ route('faq', ['lang' => app()->getLocale()]) }}">{{ __('public.footer.faq') }}</a>
            @foreach(($footerPages ?? collect()) as $footerPage)
                <a href="{{ route('public.page', ['slug' => $footerPage->slug, 'lang' => app()->getLocale()]) }}">{{ $footerPage->title }}</a>
            @endforeach
            <button type="button" data-consent-settings>{{ __('public.consent.settings') }}</button>
        </nav>
        <div class="site-footer__copyright">
            © {{ date('Y') }} {{ __('public.brand') }}. {{ __('public.footer.rights') }}
        </div>
    </div>
</footer>
