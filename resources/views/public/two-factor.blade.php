@extends('public.layout')

@section('title', 'Подтверждение входа — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Подтвердите вход</h1>
    <p>Мы отправили шестизначный код на электронную почту или в подключённый Telegram.</p>
    @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('two-factor.verify') }}">
        @csrf
        <label><span>Код</span><input name="code" inputmode="numeric" maxlength="6" required autofocus></label>
        <p><button class="button" type="submit">Продолжить</button></p>
    </form>
</section>
@endsection
