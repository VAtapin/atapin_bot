<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#f6f2e9">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Семейное древо</title>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <script>
        window.familyAppConfig = @json($familyAppConfig);
    </script>
    @vite(['resources/js/app.js'])
</head>
<body>
    <main class="app-shell">
        <header class="topbar">
            <div class="brand">
                <span class="brand-mark">🌳</span>
                <div>
                    <h1>{{ $familyName }}</h1>
                    <p>люди, истории и важные даты</p>
                </div>
            </div>
            @if ($hasBrowserSession)
                <form method="post" action="{{ route('family.logout') }}">
                    @csrf
                    <button class="logout-button" type="submit">Выйти</button>
                </form>
            @endif
        </header>

        <nav class="tabs" aria-label="Разделы">
            <button class="tab is-active" type="button" data-tab="tree">Древо</button>
            <button class="tab" type="button" data-tab="list">Список</button>
            <button class="tab" type="button" data-tab="gallery">Фото</button>
            <button class="tab" type="button" data-tab="birthdays">Дни рождения</button>
            <button class="tab" type="button" data-tab="me">Моя семья</button>
        </nav>

        <section id="tree-view" class="view">
            <form id="filters" class="filters">
                <input name="q" type="search" placeholder="Найти человека…" autocomplete="off" aria-label="Поиск">
                <select name="gender" aria-label="Пол">
                    <option value="">Любой пол</option>
                    <option value="female">Женщины</option>
                    <option value="male">Мужчины</option>
                </select>
                <select id="city" name="city" aria-label="Место">
                    <option value="">Все места</option>
                </select>
                <select name="living" aria-label="Статус">
                    <option value="">Все</option>
                    <option value="1">Ныне живущие</option>
                    <option value="0">Ушедшие</option>
                </select>
                <select name="depth" aria-label="Глубина ветви">
                    <option value="1">1 поколение</option>
                    <option value="2" selected>2 поколения</option>
                    <option value="3">3 поколения</option>
                    <option value="4">4 поколения</option>
                </select>
                <select name="relation" aria-label="Родство со мной">
                    <option value="">Все родственники</option>
                    <option value="parents">Мои родители</option>
                    <option value="grandparents">Мои бабушки и дедушки</option>
                    <option value="spouses">Мой супруг / супруга</option>
                    <option value="children">Мои дети</option>
                    <option value="grandchildren">Мои внуки</option>
                    <option value="siblings">Мои братья и сёстры</option>
                    <option value="nephews">Мои племянники</option>
                </select>
                <button id="reset-filters" type="button" title="Сбросить фильтры">↺</button>
            </form>

            <div id="tree" aria-label="Семейное древо"></div>
            <div id="people-list" class="people-list" hidden></div>
            <div id="tree-meta" class="tree-meta"></div>
            <div class="tree-controls">
                <button id="zoom-out" class="icon-button" type="button" aria-label="Уменьшить">−</button>
                <button id="zoom-in" class="icon-button" type="button" aria-label="Увеличить">+</button>
                <button id="fit-tree" class="icon-button fit-button" type="button">Вписать ветвь</button>
                <button id="my-branch" class="icon-button fit-button" type="button">Моя ветвь</button>
                <button id="all-tree" class="icon-button fit-button" type="button">Всё дерево</button>
            </div>
            <div id="empty" class="empty" hidden>
                <strong>Никого не найдено</strong>
                <span>Попробуйте изменить фильтры.</span>
            </div>
        </section>

        <section id="birthdays-view" class="birthdays" hidden>
            <p class="birthday-intro">Ближайшие семейные праздники</p>
            <div id="birthday-list" class="birthday-list"></div>
        </section>

        <section id="gallery-view" class="gallery-view" hidden>
            <div id="gallery-grid" class="gallery-grid"></div>
        </section>

        <section id="me-view" class="me-view" hidden>
            <div id="me-content"></div>
        </section>
    </main>

    <div id="loading" class="loading" aria-label="Загрузка"></div>
    <div id="error" class="error" hidden>
        <span id="error-message"></span>
        <div id="error-actions" class="error-actions"></div>
    </div>

    <aside id="auth-panel" class="auth-panel" hidden>
        <section class="auth-card">
            <span class="brand-mark">🌳</span>
            <h2>Вход в семейный архив</h2>
            <p>Используйте Telegram или личный логин, выданный администратором.</p>
            <a id="telegram-login-link" class="telegram-login" href="{{ route('telegram.login') }}">
                Войти через Telegram
            </a>
            <div class="auth-divider"><span>или</span></div>
            <form method="post" action="{{ route('family.login') }}" class="auth-form">
                @csrf
                <label>
                    <span>Логин</span>
                    <input name="login" value="{{ old('login') }}" autocomplete="username" required>
                </label>
                <label>
                    <span>Пароль</span>
                    <input name="password" type="password" autocomplete="current-password" required>
                </label>
                <button type="submit">Войти</button>
            </form>
            <p id="auth-error" class="auth-error">{{ $loginError }}</p>
        </section>
    </aside>

    <aside id="person-sheet" class="sheet" hidden>
        <article class="sheet-card">
            <button id="close-person" class="icon-button sheet-close" type="button" aria-label="Закрыть">×</button>
            <div id="person-content"></div>
        </article>
    </aside>
</body>
</html>
