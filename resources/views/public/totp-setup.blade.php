@extends('public.layout')

@section('title', 'Приложение-аутентификатор — Я и дом мой')

@section('content')
<section class="content-card" style="max-width:620px">
    <h1>Подключить приложение-аутентификатор</h1>
    <p>Откройте Яндекс ID, 2FAS, Aegis, Microsoft Authenticator или другое приложение с поддержкой TOTP и отсканируйте QR-код.</p>

    <div style="display:grid;place-items:center;margin:24px 0">
        <img src="{{ $qrCode }}" width="240" height="240" alt="QR-код для подключения приложения-аутентификатора">
    </div>

    <details style="margin-bottom:22px">
        <summary>Не получается отсканировать QR-код</summary>
        <p>Введите этот секретный ключ вручную:</p>
        <code style="display:block;padding:12px;overflow-wrap:anywhere;background:var(--paper);border-radius:10px">{{ $secret }}</code>
    </details>

    @if($errors->any())<p class="error">{{ $errors->first() }}</p>@endif
    <form method="post" action="{{ route('totp.confirm') }}">
        @csrf
        <label>
            <span>Код из приложения</span>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus>
        </label>
        <p><button class="button" type="submit">Подтвердить и подключить</button></p>
    </form>
</section>
@endsection
