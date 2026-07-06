@extends('public.layout')

@section('content')
    <section class="content-card content-card--narrow">
            <span class="eyebrow">Оплата</span>
            <h1>Оплата тарифа «{{ $plan->name }}»</h1>
            <p class="muted">
                Дерево: {{ $tree->name }}.
                Сумма: {{ number_format((float) $payment->amount, 2, ',', ' ') }} {{ $payment->currency }}.
            </p>
            <p class="muted">
                Сейчас откроется защищённая форма CloudPayments. Если форма не открылась автоматически,
                нажмите кнопку ниже.
            </p>
            <button class="button" type="button" data-cloudpayments-open>
                Открыть оплату
            </button>
            <a class="button secondary" href="{{ route('billing.return', ['tree' => $tree, 'status' => 'cancel']) }}">
                Отменить
            </a>
    </section>
@endsection

@push('scripts')
    <script src="https://widget.cloudpayments.ru/bundles/cloudpayments.js"></script>
    <script>
        (() => {
            const openButton = document.querySelector('[data-cloudpayments-open]');
            const successUrl = @json(route('billing.return', ['tree' => $tree, 'status' => 'success']));
            const cancelUrl = @json(route('billing.return', ['tree' => $tree, 'status' => 'cancel']));
            const options = {
                publicId: @json($publicId),
                description: @json('Тариф «'.$plan->name.'» — '.$tree->name),
                amount: {{ (float) $payment->amount }},
                currency: @json($payment->currency),
                invoiceId: @json((string) $payment->provider_reference),
                accountId: @json($payment->user?->email ?: 'tree-'.$tree->id),
                data: {
                    tree_id: @json((string) $tree->id),
                    plan_id: @json((string) $plan->id),
                    user_id: @json((string) $payment->user_id),
                    idempotency_key: @json($payment->idempotency_key),
                },
            };
            const openPayment = () => {
                if (! window.cp?.CloudPayments) {
                    return;
                }
                new window.cp.CloudPayments().pay('charge', options, {
                    onSuccess: () => window.location.assign(successUrl),
                    onFail: () => window.location.assign(cancelUrl),
                    onComplete: () => {},
                });
            };

            openButton?.addEventListener('click', openPayment);
            window.addEventListener('load', openPayment, { once: true });
        })();
    </script>
@endpush
