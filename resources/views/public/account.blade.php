@extends('public.layout')

@section('title', 'Способы входа — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Способы входа</h1>
    <p>Все мессенджеры подключаются к одной учётной записи. Так один человек не появится в системе несколько раз.</p>
    @if(session('status'))<p style="color:#2f6c3d">{{ session('status') }}</p>@endif
    <div style="display:grid;gap:10px">
        <article style="padding:14px;border:1px solid var(--line);border-radius:12px">
            <strong>Email и пароль</strong><br><small>{{ $user->email }}</small>
        </article>
        @foreach($user->externalIdentities as $identity)
            <article style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px;border:1px solid var(--line);border-radius:12px">
                <div><strong>{{ ucfirst($identity->provider) }}</strong><br><small>{{ $identity->username ? '@'.$identity->username : $identity->provider_user_id }} · последний вход {{ $identity->last_login_at?->format('d.m.Y H:i') ?: 'неизвестно' }}</small></div>
                <form method="post" action="{{ route('account.identities.unlink', $identity) }}">@csrf @method('delete')<button class="button secondary" type="submit">Отключить</button></form>
            </article>
        @endforeach
    </div>
    <p><a class="button" href="{{ route('telegram.login', ['link' => 1, 'return' => route('account')]) }}">Подключить Telegram</a></p>
    <p style="color:var(--muted)">VK, OK и MAX будут подключаться здесь же после выдачи приложению соответствующих ключей.</p>
</section>
@endsection
