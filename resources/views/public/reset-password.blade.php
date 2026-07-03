@extends('public.layout')
@section('title', 'Новый пароль — Я и дом мой')
@section('content')
<section class="content-card">
    <h1>Новый пароль</h1>
    <form method="post" action="{{ route('password.update') }}" class="form-grid">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <label class="wide"><span>Email</span><input name="email" type="email" value="{{ old('email', $email) }}" required></label>
        <label><span>Новый пароль</span><input name="password" type="password" required></label>
        <label><span>Повторите пароль</span><input name="password_confirmation" type="password" required></label>
        @if($errors->any())<p class="wide field-error">{{ $errors->first() }}</p>@endif
        <div class="wide"><button class="button" type="submit">Сохранить пароль</button></div>
    </form>
</section>
@endsection
