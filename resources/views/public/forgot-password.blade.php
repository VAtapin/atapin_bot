@extends('public.layout')
@section('title', 'Восстановление доступа — Я и дом мой')
@section('content')
<section class="content-card">
    <h1>Восстановление доступа</h1>
    <p>Укажите email учётной записи. Мы отправим одноразовую ссылку.</p>
    @if(session('status'))<p>{{ session('status') }}</p>@endif
    <form method="post" action="{{ route('password.email') }}">@csrf
        <label><span>Email</span><input name="email" type="email" value="{{ old('email') }}" required autofocus></label>
        @error('email')<p class="field-error">{{ $message }}</p>@enderror
        <p><button class="button" type="submit">Отправить ссылку</button></p>
    </form>
</section>
@endsection
