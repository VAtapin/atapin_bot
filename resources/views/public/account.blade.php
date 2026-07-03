@extends('public.layout')

@section('title', 'Способы входа — Я и дом мой')

@section('content')
<section class="content-card">
    <h1>Безопасность и способы входа</h1>
    @if(request()->boolean('welcome'))
        <article style="margin-bottom:22px;padding:18px;border:2px solid var(--green);border-radius:14px">
            <strong>Дерево создано. Хотите дополнительно защитить аккаунт?</strong>
            <p>Подключение приложения-аутентификатора необязательно и займёт около минуты.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <a class="button" href="{{ route('totp.setup') }}">Подключить сейчас</a>
                <a class="button secondary" href="{{ route('trees.choose') }}">Позже — перейти к дереву</a>
            </div>
        </article>
    @endif
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
    <article style="margin-top:22px;padding:18px;border:1px solid var(--line);border-radius:12px">
        <strong>Приложение-аутентификатор</strong>
        @if($user->two_factor_confirmed_at)
            <p style="color:#2f6c3d">Подключено {{ $user->two_factor_confirmed_at->format('d.m.Y H:i') }}. Поддерживаются Яндекс ID, 2FAS, Aegis, Microsoft Authenticator и другие приложения TOTP.</p>
            @if($user->two_factor_required)
                <p><strong>Двухфакторная защита обязательна для этой учётной записи.</strong></p>
            @endif
            <p><a class="button secondary" href="{{ route('totp.setup') }}">Подключить заново</a></p>
            @unless($user->is_super_admin || $user->two_factor_required)
                <form method="post" action="{{ route('totp.destroy') }}" style="display:grid;gap:8px;max-width:360px">
                    @csrf
                    @method('delete')
                    <label><span>Код для отключения</span><input name="code" inputmode="numeric" maxlength="6" required></label>
                    <button class="button secondary" type="submit">Отключить приложение</button>
                </form>
            @endunless
        @else
            <p>Получайте одноразовые коды без email, Telegram и подключения телефона к интернету.</p>
            @if($user->two_factor_required)
                <p><strong>Администратор сделал двухфакторную защиту обязательной.</strong></p>
            @endif
            <a class="button" href="{{ route('totp.setup') }}">Подключить приложение</a>
        @endif
    </article>
    <p style="color:var(--muted)">VK, OK и MAX будут подключаться здесь же после выдачи приложению соответствующих ключей.</p>
</section>
@endsection
