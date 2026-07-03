<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_trees', function (Blueprint $table): void {
            $table->string('crest_path')->nullable()->after('accent_color');
            $table->text('custom_bot_token')->nullable()->after('crest_path');
            $table->string('custom_bot_username')->nullable()->after('custom_bot_token');
            $table->string('custom_bot_webhook_secret')->nullable()->after('custom_bot_username');
            $table->timestamp('custom_bot_verified_at')->nullable()->after('custom_bot_webhook_secret');
            $table->foreignId('deletion_requested_by_user_id')
                ->nullable()
                ->after('deletion_scheduled_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->text('deletion_reason')->nullable()->after('deletion_requested_by_user_id');
        });

        Schema::table('tree_invitations', function (Blueprint $table): void {
            $table->text('token_ciphertext')->nullable()->after('token_hash');
            $table->foreignId('revoked_by_user_id')
                ->nullable()
                ->after('revoked_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('external_identities', function (Blueprint $table): void {
            $table->string('provider_email')->nullable()->after('username');
            $table->timestamp('verified_at')->nullable()->after('last_login_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('merged_into_user_id')
                ->nullable()
                ->after('last_tree_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('merged_at')->nullable()->after('merged_into_user_id');
        });

        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->string('status', 20)->default('published')->after('content')->index();
            $table->timestamp('published_at')->nullable()->after('is_published');
        });

        Schema::create('cms_page_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cms_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->longText('content');
            $table->string('status', 20)->default('draft');
            $table->timestamps();
        });

        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->string('group', 30)->default('general')->after('key')->index();
            $table->boolean('is_secret')->default(false)->after('type');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('description');
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->boolean('cancel_at_period_end')->default(false)->after('cancelled_at');
            $table->timestamp('archived_at')->nullable()->after('cancel_at_period_end');
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->after('provider_reference')->unique();
            $table->string('description')->nullable()->after('currency');
            $table->timestamp('period_starts_at')->nullable()->after('description');
            $table->timestamp('period_ends_at')->nullable()->after('period_starts_at');
        });

        Schema::create('payment_webhook_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30)->index();
            $table->string('event_id')->nullable();
            $table->string('status', 20)->default('received')->index();
            $table->string('signature')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id']);
        });

        Schema::table('family_events', function (Blueprint $table): void {
            $table->unsignedInteger('reminder_minutes')->nullable()->after('is_published');
        });
        Schema::create('family_event_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('family_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('occurrence_at');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['family_event_id', 'user_id', 'occurrence_at'], 'event_user_occurrence_unique');
        });

        $this->seedPlatformSettings();
    }

    private function seedPlatformSettings(): void
    {
        $settings = [
            ['smtp_enabled', 'mail', 'boolean', 'Использовать внешний SMTP', '0', false, 10, 'Включает отправку писем через указанный ниже сервер.'],
            ['smtp_preset', 'mail', 'string', 'Провайдер почты', 'custom', false, 20, 'Gmail, Yandex, Mail.ru, Microsoft 365 или собственный SMTP.'],
            ['smtp_host', 'mail', 'string', 'SMTP-сервер', null, false, 30, 'Например smtp.gmail.com.'],
            ['smtp_port', 'mail', 'integer', 'Порт', '587', false, 40, 'Обычно 587 для STARTTLS или 465 для SSL.'],
            ['smtp_encryption', 'mail', 'string', 'Шифрование', 'tls', false, 50, 'tls, ssl или пусто.'],
            ['smtp_username', 'mail', 'string', 'Имя пользователя', null, false, 60, 'Обычно полный адрес электронной почты.'],
            ['smtp_password', 'mail', 'string', 'Пароль приложения', null, true, 70, 'Для Gmail используйте пароль приложения, а не основной пароль.'],
            ['smtp_from_address', 'mail', 'string', 'Адрес отправителя', null, false, 80, null],
            ['smtp_from_name', 'mail', 'string', 'Имя отправителя', 'Я и дом мой', false, 90, null],
            ['smtp_timeout', 'mail', 'integer', 'Таймаут, секунд', '15', false, 100, null],
            ['billing_enabled', 'billing', 'boolean', 'Включить онлайн-платежи', '0', false, 110, 'Показывать оплату только после полной настройки провайдера.'],
            ['billing_provider', 'billing', 'string', 'Платёжный провайдер', 'manual', false, 120, 'manual, stripe или yookassa.'],
            ['billing_test_mode', 'billing', 'boolean', 'Тестовый режим', '1', false, 130, null],
            ['billing_secret_key', 'billing', 'string', 'Секретный ключ провайдера', null, true, 140, null],
            ['billing_shop_id', 'billing', 'string', 'Shop ID / Account ID', null, false, 150, null],
            ['billing_webhook_secret', 'billing', 'string', 'Секрет webhook', null, true, 160, null],
        ];

        foreach ($settings as [$key, $group, $type, $label, $value, $secret, $sort, $description]) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $key],
                [
                    'group' => $group,
                    'type' => $type,
                    'label' => $label,
                    'value' => $value,
                    'is_secret' => $secret,
                    'sort_order' => $sort,
                    'description' => $description,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_event_reminders');
        Schema::table('family_events', function (Blueprint $table): void {
            $table->dropColumn('reminder_minutes');
        });
        Schema::dropIfExists('payment_webhook_logs');
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'description', 'period_starts_at', 'period_ends_at']);
        });
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['cancel_at_period_end', 'archived_at']);
        });
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn(['group', 'is_secret', 'sort_order']);
        });
        Schema::dropIfExists('cms_page_versions');
        Schema::table('cms_pages', function (Blueprint $table): void {
            $table->dropColumn(['status', 'published_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_user_id');
            $table->dropColumn('merged_at');
        });
        Schema::table('external_identities', function (Blueprint $table): void {
            $table->dropColumn(['provider_email', 'verified_at']);
        });
        Schema::table('tree_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropColumn('token_ciphertext');
        });
        Schema::table('family_trees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deletion_requested_by_user_id');
            $table->dropColumn([
                'crest_path',
                'custom_bot_token',
                'custom_bot_username',
                'custom_bot_webhook_secret',
                'custom_bot_verified_at',
                'deletion_reason',
            ]);
        });
    }
};
