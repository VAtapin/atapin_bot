<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('family_trees', 'region')) {
            Schema::table('family_trees', function (Blueprint $table): void {
                $table->string('region', 10)->default('eu')->after('locale')->index();
            });
        }

        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 60)->unique();
                $table->string('name');
                $table->string('provider', 30)->index();
                $table->string('region', 10)->default('eu')->index();
                $table->string('currency', 3)->default('EUR')->index();
                $table->boolean('is_active')->default(false)->index();
                $table->boolean('test_mode')->default(true);
                $table->text('credentials')->nullable();
                $table->text('webhook_secret')->nullable();
                $table->text('instructions')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['region', 'currency', 'is_active'], 'pay_methods_region_currency_active_idx');
            });
        }

        if (! Schema::hasTable('plan_prices')) {
            Schema::create('plan_prices', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
                $table->string('region', 10)->default('eu');
                $table->string('currency', 3)->default('EUR');
                $table->decimal('price_monthly', 10, 2)->default(0);
                $table->string('provider_price_reference')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['plan_id', 'region', 'currency'], 'plan_price_region_currency_unique');
            });
        }

        $now = now();
        $legacyProvider = (string) (DB::table('platform_settings')->where('key', 'billing_provider')->value('value') ?: 'manual');
        $billingEnabled = filter_var(
            DB::table('platform_settings')->where('key', 'billing_enabled')->value('value') ?: false,
            FILTER_VALIDATE_BOOL,
        );
        $legacyTestMode = filter_var(
            DB::table('platform_settings')->where('key', 'billing_test_mode')->value('value') ?? true,
            FILTER_VALIDATE_BOOL,
        );

        foreach ([
            ['stripe_eu', 'Stripe / карты / SEPA', 'stripe', 'eu', 'EUR', $billingEnabled && $legacyProvider === 'stripe', 10, 'Для Европы: карты, SEPA и другие способы внутри Stripe Checkout.'],
            ['paypal_eu', 'PayPal', 'paypal', 'eu', 'EUR', false, 20, 'Для Европы: PayPal Checkout. Заполните Client ID и Secret.'],
            ['manual_eu', 'Ручная оплата', 'manual', 'eu', 'EUR', $billingEnabled && $legacyProvider === 'manual', 90, 'Резервный способ: создаётся заявка, администратор подтверждает оплату вручную.'],
            ['yookassa_ru', 'ЮKassa', 'yookassa', 'ru', 'RUB', $billingEnabled && $legacyProvider === 'yookassa', 10, 'Для России: ЮKassa. Заполните Shop ID и секретный ключ.'],
            ['cloudpayments_ru', 'CloudPayments', 'cloudpayments', 'ru', 'RUB', false, 20, 'Для России: CloudPayments. Заполните Public ID и API secret, после выбора способа откроется платёжный виджет.'],
            ['robokassa_ru', 'Robokassa', 'robokassa', 'ru', 'RUB', false, 30, 'Для России: Robokassa. Заполните Merchant Login, пароль #1 и пароль #2.'],
            ['manual_ru', 'Ручная оплата РФ', 'manual', 'ru', 'RUB', false, 90, 'Резервный способ для РФ: перевод/счёт с ручным подтверждением.'],
        ] as [$code, $name, $provider, $region, $currency, $active, $sort, $instructions]) {
            DB::table('payment_methods')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'provider' => $provider,
                    'region' => $region,
                    'currency' => $currency,
                    'is_active' => $active,
                    'test_mode' => $legacyTestMode,
                    'credentials' => null,
                    'instructions' => $instructions,
                    'sort_order' => $sort,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        foreach (DB::table('plans')->get() as $plan) {
            $eur = (float) $plan->price_monthly;
            DB::table('plan_prices')->updateOrInsert(
                ['plan_id' => $plan->id, 'region' => 'eu', 'currency' => 'EUR'],
                [
                    'price_monthly' => $eur,
                    'provider_price_reference' => $plan->provider_price_reference ?? null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            DB::table('plan_prices')->updateOrInsert(
                ['plan_id' => $plan->id, 'region' => 'ru', 'currency' => 'RUB'],
                [
                    'price_monthly' => $eur <= 0 ? 0 : round($eur * 100),
                    'provider_price_reference' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('payment_methods');

        if (Schema::hasColumn('family_trees', 'region')) {
            Schema::table('family_trees', function (Blueprint $table): void {
                $table->dropColumn('region');
            });
        }
    }
};
