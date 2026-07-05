@if($plans->isNotEmpty())
    <section class="home-section public-section public-wrap" data-analytics-view="view_pricing">
        <p class="section-kicker">{{ __('public.home.pricing_kicker') }}</p>
        <h2>{{ $translation->title }}</h2>
        @if($translation->lead)<p class="section-lead">{{ $translation->lead }}</p>@endif
        <div class="plan-grid">
            @foreach($plans as $plan)
                @php
                    $planName = filled($plan->name)
                        ? $plan->name
                        : (\Illuminate\Support\Facades\Lang::has("public.plans.{$plan->code}.name")
                            ? __("public.plans.{$plan->code}.name")
                            : $plan->code);
                    $planDescription = filled($plan->description)
                        ? $plan->description
                        : (\Illuminate\Support\Facades\Lang::has("public.plans.{$plan->code}.description")
                            ? __("public.plans.{$plan->code}.description")
                            : '');
                @endphp
                <article class="plan-card">
                    <h3>{{ $planName }}</h3>
                    @if($planDescription !== '')
                        <p>{{ $planDescription }}</p>
                    @endif
                    <strong class="plan-card__price">
                        {{ $plan->price_monthly > 0
                            ? __('public.home.per_month', ['price' => $plan->price_monthly, 'currency' => $plan->currency])
                            : __('public.home.free') }}
                    </strong>
                    <p class="plan-card__limits">{{ __('public.home.plan_limits', [
                        'people' => number_format($plan->people_limit, 0, ',', ' '),
                        'storage' => round($plan->storage_limit_bytes / 1073741824, 1),
                    ]) }}</p>
                    @if($registrationEnabled)
                        <a class="button" data-analytics-click="cta_register_click" href="{{ route('register') }}">{{ __('public.home.start') }}</a>
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif
