<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'Я и дом мой — семейная история и память рода')">
    <title>@yield('title', 'Я и дом мой')</title>
    <style>
        :root { --ink:#26251f; --muted:#777268; --paper:#fbf8f1; --card:#fffdf8; --green:#68734b; --line:#ddd5c8; }
        * { box-sizing:border-box } body { margin:0; color:var(--ink); background:var(--paper); font:16px/1.55 system-ui,sans-serif }
        a { color:inherit } .wrap { width:min(1120px,calc(100% - 32px)); margin:auto }
        header { display:flex; align-items:center; justify-content:space-between; padding:20px 0 }
        .logo { display:flex; align-items:center; gap:10px; text-decoration:none }.logo b { font:700 22px Georgia,serif }.logo span { font-size:28px }
        nav { display:flex; gap:18px; align-items:center } nav a { text-decoration:none }
        .button { display:inline-block; padding:11px 18px; border-radius:11px; background:var(--green); color:#fff; font-weight:700; text-decoration:none; border:0 }
        .button.secondary { background:transparent; color:var(--green); border:1px solid var(--green) }
        main { min-height:calc(100vh - 180px) } footer { margin-top:70px; padding:28px 0; border-top:1px solid var(--line); color:var(--muted) }
        footer .wrap { display:flex; gap:18px; flex-wrap:wrap } footer a { text-decoration:none }
        .content-card { max-width:820px; margin:50px auto; padding:34px; border:1px solid var(--line); border-radius:22px; background:var(--card) }
        .content-card h1 { margin-top:0; font:700 38px/1.1 Georgia,serif }.content-card p { white-space:pre-line }
        label { display:grid; gap:5px } input,textarea,select { width:100%; padding:11px; border:1px solid var(--line); border-radius:10px; background:#fff }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px }.form-grid .wide { grid-column:1/-1 }
        .error { color:#a82929 } @media(max-width:700px){ nav>a:not(.button){display:none}.form-grid{grid-template-columns:1fr}.content-card{padding:22px} }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <a class="logo" href="{{ route('home') }}"><span>🌳</span><b>Я и дом мой</b></a>
        <nav>
            <a href="{{ route('public.page', 'about') }}">О проекте</a>
            <a href="{{ route('public.page', 'contacts') }}">Контакты</a>
            <a class="button secondary" href="/admin/login">Войти</a>
            <a class="button" href="{{ route('register') }}">Создать дерево</a>
        </nav>
    </header>
</div>
<main>@yield('content')</main>
<footer>
    <div class="wrap">
        <span>© {{ date('Y') }} «Я и дом мой»</span>
        @foreach(($footerPages ?? collect()) as $footerPage)
            <a href="{{ route('public.page', $footerPage->slug) }}">{{ $footerPage->title }}</a>
        @endforeach
    </div>
</footer>
</body>
</html>
