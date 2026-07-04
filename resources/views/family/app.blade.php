<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#f6f2e9">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $familyName }} — {{ __('miniapp.title_suffix') }}</title>
    @if(($familyAppConfig['platform'] ?? 'web') === 'telegram')
        <script src="https://telegram.org/js/telegram-web-app.js"></script>
    @endif
    <script>
        window.familyAppConfig = @json($familyAppConfig);
        window.familyAppConfig.locale = @json(app()->getLocale());
        window.familyAppI18n = @json(__('miniapp.js'));
    </script>
    <style>:root{--family-accent:{{ $familyAccent }};--family-accent-text:{{ $familyAccentText }};}</style>
    @vite(['resources/js/app.js'])
</head>
<body>
    <main class="app-shell">
        <header class="topbar">
            <div class="brand">
                <span class="brand-mark">
                    @if($familyCrestUrl)<img src="{{ $familyCrestUrl }}" alt="{{ __('miniapp.crest_alt') }}" loading="eager" decoding="async">@else 🌳 @endif
                </span>
                <div>
                    <h1>{{ $familyName }}</h1>
                    <p>{{ $familySubtitle }}</p>
                </div>
            </div>
            @if ($hasBrowserSession)
                <div class="topbar-actions">
                    @if(!empty($familyAppConfig['managementUrl']))
                        <a class="logout-button" href="{{ $familyAppConfig['managementUrl'] }}" title="{{ __('miniapp.manage') }}">
                            <span class="action-icon" aria-hidden="true">⚙</span><span class="action-label">{{ __('miniapp.manage') }}</span>
                        </a>
                    @endif
                    <form method="post" action="{{ route('family.logout') }}">
                        @csrf
                        <button class="logout-button" type="submit" title="{{ __('miniapp.logout') }}">
                            <span class="action-icon" aria-hidden="true">↪</span><span class="action-label">{{ __('miniapp.logout') }}</span>
                        </button>
                    </form>
                </div>
            @endif
        </header>

        <nav class="tabs" aria-label="{{ __('miniapp.sections') }}">
            <button class="tab is-active" type="button" data-tab="tree"><span aria-hidden="true">🌳</span>{{ __('miniapp.tabs.tree') }}</button>
            <button class="tab" type="button" data-tab="list"><span aria-hidden="true">☷</span>{{ __('miniapp.tabs.list') }}</button>
            <button class="tab" type="button" data-tab="gallery"><span aria-hidden="true">▧</span>{{ __('miniapp.tabs.gallery') }}</button>
            <button class="tab" type="button" data-tab="birthdays"><span aria-hidden="true">🎂</span>{{ __('miniapp.tabs.birthdays') }}</button>
            <button class="tab" type="button" data-tab="events"><span aria-hidden="true">◷</span>{{ __('miniapp.tabs.events') }}</button>
            <button class="tab" type="button" data-tab="me"><span aria-hidden="true">♥</span>{{ __('miniapp.tabs.me') }}</button>
        </nav>

        <section id="tree-view" class="view">
            <div class="mobile-tree-toolbar">
                <button id="open-filters" type="button" aria-expanded="false">
                    <span aria-hidden="true">⌕</span>
                    <span>{{ __('miniapp.filters.heading') }}</span>
                    <b aria-hidden="true">☰</b>
                </button>
            </div>
            <button id="filter-backdrop" class="filter-backdrop" type="button" aria-label="{{ __('miniapp.filters.close') }}" hidden></button>
            <form id="filters" class="filters">
                <div class="mobile-filter-header">
                    <strong>{{ __('miniapp.filters.heading') }}</strong>
                    <button id="close-filters" type="button" aria-label="{{ __('miniapp.filters.close') }}">×</button>
                </div>
                <input name="q" type="search" placeholder="{{ __('miniapp.filters.search') }}" autocomplete="off" aria-label="{{ __('miniapp.filters.search_label') }}">
                <select name="gender" aria-label="{{ __('miniapp.filters.gender') }}">
                    <option value="">{{ __('miniapp.filters.gender_all') }}</option>
                    <option value="female">{{ __('miniapp.filters.women') }}</option>
                    <option value="male">{{ __('miniapp.filters.men') }}</option>
                </select>
                <select id="city" name="city" aria-label="{{ __('miniapp.filters.place') }}">
                    <option value="">{{ __('miniapp.filters.places_all') }}</option>
                </select>
                <select name="living" aria-label="{{ __('miniapp.filters.status') }}">
                    <option value="">{{ __('miniapp.filters.all') }}</option>
                    <option value="1">{{ __('miniapp.filters.living') }}</option>
                    <option value="0">{{ __('miniapp.filters.deceased') }}</option>
                </select>
                <select name="depth" aria-label="{{ __('miniapp.filters.depth') }}">
                    <option value="1">{{ __('miniapp.filters.generation_1') }}</option>
                    <option value="2" selected>{{ __('miniapp.filters.generation_2') }}</option>
                    <option value="3">{{ __('miniapp.filters.generation_3') }}</option>
                    <option value="4">{{ __('miniapp.filters.generation_4') }}</option>
                </select>
                <select name="relation" aria-label="{{ __('miniapp.filters.relation') }}">
                    <option value="">{{ __('miniapp.filters.relatives_all') }}</option>
                    <option value="parents">{{ __('miniapp.filters.parents') }}</option>
                    <option value="grandparents">{{ __('miniapp.filters.grandparents') }}</option>
                    <option value="spouses">{{ __('miniapp.filters.spouses') }}</option>
                    <option value="children">{{ __('miniapp.filters.children') }}</option>
                    <option value="grandchildren">{{ __('miniapp.filters.grandchildren') }}</option>
                    <option value="siblings">{{ __('miniapp.filters.siblings') }}</option>
                    <option value="nephews">{{ __('miniapp.filters.nephews') }}</option>
                </select>
                <button id="reset-filters" type="button" title="{{ __('miniapp.filters.reset') }}">↺</button>
                <button id="apply-filters" class="apply-filters" type="button">{{ __('miniapp.filters.apply') }}</button>
            </form>

            <div id="tree" aria-label="{{ __('miniapp.tree.label') }}"></div>
            <div id="people-list" class="people-list" hidden></div>
            <div id="tree-meta" class="tree-meta"></div>
            <div class="tree-controls">
                <button id="zoom-out" class="icon-button" type="button" aria-label="{{ __('miniapp.tree.zoom_out') }}">−</button>
                <button id="zoom-in" class="icon-button" type="button" aria-label="{{ __('miniapp.tree.zoom_in') }}">+</button>
                <button id="fit-tree" class="icon-button fit-button" type="button">{{ __('miniapp.tree.fit') }}</button>
                <button id="my-branch" class="icon-button fit-button" type="button">{{ __('miniapp.tree.mine') }}</button>
                <button id="all-tree" class="icon-button fit-button" type="button">{{ __('miniapp.tree.all') }}</button>
            </div>
            <div id="empty" class="empty" hidden>
                <strong>{{ __('miniapp.tree.empty') }}</strong>
                <span>{{ __('miniapp.tree.empty_hint') }}</span>
            </div>
        </section>

        <section id="birthdays-view" class="birthdays" hidden>
            <p class="birthday-intro">{{ __('miniapp.birthdays_intro') }}</p>
            <div id="birthday-list" class="birthday-list"></div>
            <div id="anniversary-list" class="birthday-list"></div>
            <div id="congratulation-inbox" class="congratulation-inbox"></div>
        </section>

        <section id="gallery-view" class="gallery-view" hidden>
            <div id="gallery-grid" class="gallery-grid"></div>
            <button id="gallery-more" class="gallery-more" type="button" hidden>{{ __('miniapp.gallery_more') }}</button>
        </section>

        <section id="events-view" class="events-view" hidden>
            <h2>{{ __('miniapp.events_title') }}</h2>
            <div id="events-list" class="events-list"></div>
            <details class="event-archive">
                <summary>{{ __('miniapp.events_archive') }}</summary>
                <div id="events-archive" class="events-list"></div>
            </details>
        </section>

        <section id="me-view" class="me-view" hidden>
            <div id="me-content"></div>
        </section>
    </main>

    <div id="loading" class="loading" aria-label="{{ __('miniapp.loading') }}"></div>
    <div id="error" class="error" hidden>
        <span id="error-message"></span>
        <div id="error-actions" class="error-actions"></div>
    </div>

    <button id="report-issue-button" class="report-issue-button" type="button">{{ __('miniapp.issue.button') }}</button>
    <aside id="report-issue-modal" class="report-issue-modal" hidden>
        <form id="report-issue-form" class="report-issue-card">
            <button class="icon-button report-issue-close" type="button" aria-label="{{ __('miniapp.filters.close') }}">×</button>
            <h2>{{ __('miniapp.issue.title') }}</h2>
            <p>{{ __('miniapp.issue.text') }}</p>
            <input name="subject" placeholder="{{ __('miniapp.issue.subject') }}" required maxlength="180">
            <textarea name="description" placeholder="{{ __('miniapp.issue.details') }}" required maxlength="5000"></textarea>
            <input name="person_id" type="hidden">
            <button type="submit">{{ __('miniapp.issue.send') }}</button>
            <small id="report-issue-message"></small>
        </form>
    </aside>

    <aside id="congratulation-modal" class="report-issue-modal" hidden>
        <form id="congratulation-form" class="report-issue-card">
            <button class="icon-button congratulation-close" type="button" aria-label="{{ __('miniapp.filters.close') }}">×</button>
            <h2>{{ __('miniapp.congratulation.title') }}</h2>
            <p id="congratulation-recipient"></p>
            <input name="occasion" type="hidden">
            <input name="person_id" type="hidden">
            <input name="partnership_id" type="hidden">
            <textarea name="message" placeholder="{{ __('miniapp.congratulation.message') }}" required minlength="2" maxlength="1000"></textarea>
            <button type="submit">{{ __('miniapp.congratulation.send') }}</button>
            <small id="congratulation-message"></small>
        </form>
    </aside>

    <aside id="auth-panel" class="auth-panel" hidden>
        <section class="auth-card">
            <span class="brand-mark">🌳</span>
            <h2>{{ __('miniapp.auth.title') }}</h2>
            <p>{{ __('miniapp.auth.text') }}</p>
            <a id="telegram-login-link"
               class="telegram-login"
               href="{{ $familyAppConfig['telegramLoginUrl'] ?? '#' }}"
               @if(empty($familyAppConfig['telegramLoginUrl'])) hidden @endif>
                {{ __('miniapp.auth.telegram') }}
            </a>
            <div class="auth-divider"><span>{{ __('miniapp.auth.or') }}</span></div>
            <form method="post" action="{{ route('login.store') }}" class="auth-form">
                @csrf
                <input name="tree_slug" type="hidden" value="{{ $familyAppConfig['treeSlug'] }}">
                <input name="return_to" type="hidden" value="{{ '/'.ltrim(request()->getRequestUri(), '/') }}">
                <label>
                    <span>{{ __('miniapp.auth.login') }}</span>
                    <input name="login" value="{{ old('login') }}" autocomplete="username" required>
                </label>
                <label>
                    <span>{{ __('miniapp.auth.password') }}</span>
                    <input name="password" type="password" autocomplete="current-password" required>
                </label>
                <button type="submit">{{ __('miniapp.auth.submit') }}</button>
            </form>
            <a id="telegram-credentials-link"
               class="telegram-credentials"
               href="{{ $familyAppConfig['telegramCredentialsUrl'] }}">
                {{ __('miniapp.auth.credentials') }}
            </a>
            <p id="auth-error" class="auth-error">{{ $loginError }}</p>
        </section>
    </aside>

    <aside id="person-sheet" class="sheet" hidden>
        <article class="sheet-card">
            <button id="close-person" class="icon-button sheet-close" type="button" aria-label="{{ __('miniapp.filters.close') }}">×</button>
            <div id="person-content"></div>
        </article>
    </aside>

    <aside id="photo-viewer" class="photo-viewer" hidden>
        <article class="photo-viewer-card">
            <button id="close-photo-viewer" class="icon-button photo-viewer-close" type="button" aria-label="{{ __('miniapp.filters.close') }}">×</button>
            <img id="photo-viewer-image" src="" alt="">
            <div id="photo-viewer-caption"></div>
        </article>
    </aside>
</body>
</html>
