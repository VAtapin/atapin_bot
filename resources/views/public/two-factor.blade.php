@extends('public.layout')

@section('title', 'Подтверждение входа — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Подтвердите вход</h1>
    @if(auth()->user()?->two_factor_confirmed_at)
        <p>Введите шестизначный код из приложения-аутентификатора.</p>
    @else
        <p>Код подтверждения отправлен доступным безопасным способом.</p>
    @endif
    @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('two-factor.verify') }}">
        @csrf
        <label><span>Код</span><input name="code" inputmode="numeric" maxlength="6" required autofocus></label>
        <p><button class="button" type="submit">Продолжить</button></p>
    </form>
</section>
@endsection
