<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $descriptions = [
            'billing_enabled' => 'Включите после настройки провайдера и webhook. Владельцы деревьев получат доступ к онлайн-оплате.',
            'billing_provider' => 'stripe — ежемесячная подписка Stripe; yookassa — платёж ЮKassa; manual — ручное подтверждение.',
            'billing_test_mode' => '1 использует тестовый ключ sk_test_…; 0 требует рабочий ключ sk_live_….',
            'billing_secret_key' => 'Секретный API-ключ провайдера. Для Stripe: Developers → API keys → Secret key.',
            'billing_shop_id' => 'Shop ID ЮKassa. При использовании Stripe оставьте поле пустым.',
            'billing_webhook_secret' => 'Для Stripe: signing secret whsec_… endpoint /api/payments/webhook/stripe.',
        ];

        foreach ($descriptions as $key => $description) {
            DB::table('platform_settings')->where('key', $key)->update(['description' => $description]);
        }
    }

    public function down(): void
    {
        // Документационные изменения безопасно оставлять при откате.
    }
};
