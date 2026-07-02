<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_super_admin')->default(false)->index();
            $table->boolean('two_factor_enabled')->default(false);
        });

        DB::table('users')->update(['is_super_admin' => true]);

        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('storage_limit_bytes')->default(536870912);
            $table->unsignedInteger('people_limit')->default(500);
            $table->unsignedInteger('member_limit')->default(25);
            $table->unsignedInteger('backup_retention_days')->default(30);
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('custom_bot')->default(false);
            $table->boolean('custom_domain')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Семейный',
            'code' => 'family',
            'description' => 'Основной тариф семейного архива',
            'storage_limit_bytes' => 5 * 1024 * 1024 * 1024,
            'people_limit' => 2000,
            'member_limit' => 100,
            'backup_retention_days' => 90,
            'price_monthly' => 0,
            'currency' => 'EUR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('family_trees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('subtitle')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('locale', 10)->default('ru');
            $table->string('timezone')->default('Europe/Berlin');
            $table->string('primary_domain')->nullable();
            $table->string('accent_color', 20)->default('#6d7651');
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('storage_used_bytes')->default(0);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('deletion_scheduled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $ownerId = DB::table('users')->orderBy('id')->value('id');
        $hasLegacyFamilyData = collect([
            'people',
            'parent_children',
            'partnerships',
            'family_events',
            'photo_albums',
            'person_photos',
            'telegram_groups',
            'telegram_users',
        ])->contains(fn (string $table): bool => DB::table($table)->exists());
        $treeId = null;

        if ($hasLegacyFamilyData) {
            $familyName = DB::table('settings')->where('key', 'family_name')->value('value')
                ?: 'Импортированное семейное дерево';
            $treeId = DB::table('family_trees')->insertGetId([
                'owner_user_id' => $ownerId,
                'plan_id' => $planId,
                'name' => $familyName,
                'slug' => 'legacy-tree',
                'subtitle' => 'Семейная история и память рода',
                'status' => 'active',
                'locale' => 'ru',
                'timezone' => 'Europe/Berlin',
                'settings' => json_encode(['is_legacy_tree' => true], JSON_UNESCAPED_UNICODE),
                'last_activity_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            'people',
            'parent_children',
            'partnerships',
            'family_events',
            'photo_albums',
            'person_photos',
            'telegram_groups',
            'telegram_updates',
            'settings',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('tree_id')
                    ->nullable()
                    ->index()
                    ->constrained('family_trees')
                    ->cascadeOnDelete();
            });

            if ($treeId) {
                DB::table($tableName)->update(['tree_id' => $treeId]);
            }
        }

        Schema::table('people', function (Blueprint $table): void {
            $table->dropUnique(['gedcom_id']);
            $table->unique(['tree_id', 'gedcom_id']);
        });
        Schema::table('person_photos', function (Blueprint $table): void {
            $table->dropUnique(['gedcom_key']);
            $table->unique(['tree_id', 'gedcom_key']);
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['tree_id', 'key']);
        });

        Schema::table('person_photos', function (Blueprint $table): void {
            $table->unsignedBigInteger('file_size')->default(0);
        });

        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('current_tree_id')
                ->nullable()
                ->after('user_id')
                ->constrained('family_trees')
                ->nullOnDelete();
        });

        Schema::create('tree_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('role', 20)->default('guest')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->json('permissions')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tree_id', 'user_id']);
        });

        if ($ownerId && $treeId) {
            DB::table('tree_memberships')->insert([
                'tree_id' => $treeId,
                'user_id' => $ownerId,
                'role' => 'owner',
                'status' => 'approved',
                'approved_by_user_id' => $ownerId,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($treeId ? DB::table('telegram_users')->orderBy('id')->get() : [] as $telegramUser) {
            $userId = DB::table('users')->insertGetId([
                'name' => trim(($telegramUser->first_name ?? '').' '.($telegramUser->last_name ?? ''))
                    ?: ($telegramUser->username ? '@'.$telegramUser->username : 'Участник семьи'),
                'email' => 'telegram_'.$telegramUser->telegram_user_id.'@idommoy.local',
                'password' => password_hash(Str::random(48), PASSWORD_BCRYPT),
                'is_active' => $telegramUser->status !== 'blocked',
                'is_super_admin' => false,
                'two_factor_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('telegram_users')->where('id', $telegramUser->id)->update([
                'user_id' => $userId,
                'current_tree_id' => $treeId,
            ]);

            DB::table('tree_memberships')->insertOrIgnore([
                'tree_id' => $treeId,
                'user_id' => $userId,
                'person_id' => $telegramUser->person_id,
                'role' => $telegramUser->is_bot_admin
                    ? 'moderator'
                    : ($telegramUser->person_id ? 'member' : 'guest'),
                'status' => $telegramUser->status,
                'approved_at' => $telegramUser->status === 'approved' ? now() : null,
                'last_seen_at' => $telegramUser->last_seen_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('external_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30)->index();
            $table->string('provider_user_id');
            $table->string('username')->nullable();
            $table->json('profile')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_user_id']);
        });

        foreach (DB::table('telegram_users')->whereNotNull('user_id')->get() as $telegramUser) {
            DB::table('external_identities')->insertOrIgnore([
                'user_id' => $telegramUser->user_id,
                'provider' => 'telegram',
                'provider_user_id' => (string) $telegramUser->telegram_user_id,
                'username' => $telegramUser->username,
                'profile' => json_encode([
                    'first_name' => $telegramUser->first_name,
                    'last_name' => $telegramUser->last_name,
                    'photo_url' => $telegramUser->photo_url ?? null,
                ], JSON_UNESCAPED_UNICODE),
                'last_login_at' => $telegramUser->last_seen_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('tree_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label')->nullable();
            $table->string('role', 20)->default('guest');
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('change_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->nullable()->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30)->index();
            $table->nullableMorphs('subject');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        Schema::create('data_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('status', 20)->default('open')->index();
            $table->string('subject');
            $table->text('description');
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tree_backups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20)->default('manual');
            $table->string('status', 20)->default('pending')->index();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->json('statistics')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tree_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->boolean('replace_existing')->default(false);
            $table->boolean('download_photos')->default(false);
            $table->json('statistics')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cms_pages', function (Blueprint $table): void {
            $table->id();
            $table->string('locale', 10)->default('ru');
            $table->string('slug');
            $table->string('title');
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->longText('content');
            $table->boolean('is_published')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['locale', 'slug']);
        });

        foreach ([
            ['about', 'О проекте', '«Я и дом мой» помогает бережно хранить семейную историю, связи, фотографии и важные даты.'],
            ['contacts', 'Контакты', 'Контактная информация проекта будет опубликована здесь.'],
            ['impressum', 'Impressum', 'Сведения о владельце и операторе сервиса.'],
            ['datenschutz', 'Datenschutz', 'Информация об обработке и защите персональных данных.'],
        ] as [$slug, $title, $content]) {
            DB::table('cms_pages')->insert([
                'locale' => 'ru',
                'slug' => $slug,
                'title' => $title,
                'content' => $content,
                'is_published' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('trial')->index();
            $table->string('provider', 30)->nullable();
            $table->string('provider_reference')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        if ($treeId) {
            DB::table('subscriptions')->insert([
                'tree_id' => $treeId,
                'plan_id' => $planId,
                'status' => 'active',
                'amount' => 0,
                'currency' => 'EUR',
                'starts_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('tree_backups');
        Schema::dropIfExists('tree_imports');
        Schema::dropIfExists('data_issues');
        Schema::dropIfExists('change_logs');
        Schema::dropIfExists('tree_invitations');
        Schema::dropIfExists('external_identities');
        Schema::dropIfExists('tree_memberships');

        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_tree_id');
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('person_photos', function (Blueprint $table): void {
            $table->dropColumn('file_size');
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->dropUnique(['tree_id', 'gedcom_id']);
            $table->unique('gedcom_id');
        });
        Schema::table('person_photos', function (Blueprint $table): void {
            $table->dropUnique(['tree_id', 'gedcom_key']);
            $table->unique('gedcom_key');
        });
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropUnique(['tree_id', 'key']);
            $table->unique('key');
        });

        foreach ([
            'people',
            'parent_children',
            'partnerships',
            'family_events',
            'photo_albums',
            'person_photos',
            'telegram_groups',
            'telegram_updates',
            'settings',
        ] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('tree_id');
            });
        }

        Schema::dropIfExists('family_trees');
        Schema::dropIfExists('plans');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_super_admin', 'two_factor_enabled']);
        });
    }
};
