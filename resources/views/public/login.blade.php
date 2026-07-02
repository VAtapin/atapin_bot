@extends('public.layout')

@section('title', 'Вход — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>{{ $tree ? 'Вход в «'.$tree->name.'»' : 'Вход в семейный архив' }}</h1>
    <p>{{ $tree ? 'После входа вы вернётесь именно в это семейное дерево.' : 'Система сама откроет доступный вам раздел.' }}</p>

    @if ($errors->any())
        <p class="error">{{ $errors->first() }}</p>
    @endif

    <form method="post" action="{{ route('login.store') }}" class="form-grid">
        @csrf
        @if ($tree)
            <input type="hidden" name="tree_slug" value="{{ $tree->slug }}">
        @endif
        @if(request('return'))
            <input type="hidden" name="return_to" value="{{ request('return') }}">
        @endif
        <label class="wide">
            <span>Email или личный логин</span>
            <input name="login" value="{{ old('login') }}" autocomplete="username" required autofocus>
        </label>
        <label class="wide">
            <span>Пароль</span>
            <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <label class="wide" style="display:flex;grid-template-columns:auto 1fr;align-items:center">
            <input name="remember" type="checkbox" value="1" style="width:auto">
            <span>Запомнить меня</span>
        </label>
        <div class="wide">
            <button class="button" type="submit">Войти</button>
            @if(config('services.telegram.oidc_client_id'))
                <a class="button secondary" href="{{ route('telegram.login', array_filter([
                    'tree' => $tree?->slug,
                    'return' => request('return'),
                ])) }}">Войти через Telegram</a>
            @endif
        </div>
    </form>
</section>
@endsection
