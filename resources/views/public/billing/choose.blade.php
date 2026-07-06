@extends('public.layout')

@section('title', 'Выбор способа оплаты')
@section('robots', 'noindex,nofollow')

@section('content')
<section class="content-card content-card--narrow">
    <p class="section-kicker">Оплата тарифа</p>
    <h1>Выберите способ оплаты</h1>
    <p>
        Тариф «{{ $plan->name }}» для дерева «{{ $tree->name }}»:
        <strong>{{ number_format((float) $amount, 2, ',', ' ') }} {{ $currency }}</strong> в месяц.
    </p>

    <div class="payment-method-grid">
        @foreach($methods as $method)
            <article class="plan-card">
                <h3>{{ $method->name }}</h3>
                <p>
                    {{ match ($method->provider) {
                        'stripe' => 'Карты, SEPA и другие способы через Stripe Checkout.',
                        'paypal' => 'Оплата через PayPal.',
                        'yookassa' => 'Российские способы оплаты через ЮKassa.',
                        'robokassa' => 'Российские способы оплаты через Robokassa.',
                        'cloudpayments' => 'Оплата через CloudPayments или ручная заявка, если виджет ещё не подключён.',
                        default => 'Ручная заявка на оплату.',
                    } }}
                </p>
                @if($method->instructions)
                    <p>{{ $method->instructions }}</p>
                @endif
                <a class="button"
                   href="{{ route('billing.checkout', ['tree' => $tree, 'plan' => $plan, 'method' => $method->code]) }}">
                    Оплатить через {{ $method->name }}
                </a>
            </article>
        @endforeach
    </div>
</section>
@endsection
