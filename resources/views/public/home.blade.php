@extends('public.layout')

@section('title', 'Я и дом мой — семейная история и память рода')

@section('content')
<style>
    .hero { padding:80px 0 55px; text-align:center }.hero h1 { max-width:850px; margin:0 auto 18px; font:700 clamp(44px,8vw,78px)/.98 Georgia,serif }
    .hero p { max-width:650px; margin:0 auto 28px; color:var(--muted); font-size:20px }.hero-actions { display:flex; justify-content:center; gap:10px; flex-wrap:wrap }
    .features,.plans { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:35px }.section { padding:55px 0 }.section h2 { text-align:center; font:700 38px Georgia,serif }
    .feature,.plan { padding:25px; border:1px solid var(--line); border-radius:18px; background:var(--card) }.feature i { font-style:normal; font-size:30px }.feature h3,.plan h3 { margin:10px 0 5px }
    .plan strong { display:block; margin:12px 0; font-size:24px }.privacy { padding:38px; border-radius:24px; background:#e9ecdf; text-align:center }
    @media(max-width:760px){.hero{padding-top:45px}.features,.plans{grid-template-columns:1fr}}
</style>
<section class="hero wrap">
    <p><strong>Семейная история и память рода</strong></p>
    <h1>История семьи должна жить</h1>
    <p>Закрытое семейное пространство для родословной, фотографий, воспоминаний и важных дат.</p>
    <div class="hero-actions">
        @if(\App\Models\PlatformSetting::value('registration_enabled', true))
            <a class="button" href="{{ route('register') }}">Создать семейное дерево</a>
        @endif
        <a class="button secondary" href="{{ route('public.page', 'about') }}">Узнать подробнее</a>
    </div>
</section>
<section class="section wrap">
    <h2>Вся семейная память в одном месте</h2>
    <div class="features">
        <article class="feature"><i>🌿</i><h3>Родословная</h3><p>Люди, поколения и понятные семейные связи.</p></article>
        <article class="feature"><i>📷</i><h3>Семейный архив</h3><p>Фотографии, альбомы, документы и истории.</p></article>
        <article class="feature"><i>🔒</i><h3>Только для своих</h3><p>Доступ получают лишь приглашённые и подтверждённые участники.</p></article>
    </div>
</section>
<section class="section wrap">
    <div class="privacy">
        <h2>Приватность по умолчанию</h2>
        <p>Семейные деревья закрыты от посторонних. Владелец сам решает, кто может смотреть и редактировать данные.</p>
    </div>
</section>
@if($plans->isNotEmpty())
<section class="section wrap">
    <h2>Тарифы</h2>
    <div class="plans">
        @foreach($plans as $plan)
            <article class="plan">
                <h3>{{ $plan->name }}</h3>
                <p>{{ $plan->description }}</p>
                <strong>{{ $plan->price_monthly > 0 ? $plan->price_monthly.' '.$plan->currency.' / месяц' : 'Бесплатно' }}</strong>
                <p>До {{ number_format($plan->people_limit, 0, ',', ' ') }} человек · {{ round($plan->storage_limit_bytes / 1073741824, 1) }} ГБ</p>
                @if(\App\Models\PlatformSetting::value('registration_enabled', true))
                    <a class="button" href="{{ route('register') }}">Начать</a>
                @endif
            </article>
        @endforeach
    </div>
</section>
@endif
@endsection
