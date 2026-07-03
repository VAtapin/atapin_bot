<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->string('bot_scope', 100)->default('platform')->after('id');
            $table->dropUnique(['telegram_update_id']);
            $table->unique(['bot_scope', 'telegram_update_id']);
        });

        DB::table('telegram_updates')->update(['bot_scope' => 'platform']);

        Schema::table('family_trees', function (Blueprint $table): void {
            $table->string('custom_bot_status', 30)
                ->default('not_configured')
                ->after('custom_bot_verified_at');
            $table->text('custom_bot_last_error')->nullable()->after('custom_bot_status');
            $table->unsignedInteger('custom_bot_pending_updates')->default(0)->after('custom_bot_last_error');
            $table->timestamp('custom_bot_checked_at')->nullable()->after('custom_bot_pending_updates');
        });

        DB::table('family_trees')
            ->whereNotNull('custom_bot_verified_at')
            ->update([
                'custom_bot_status' => 'warning',
                'custom_bot_last_error' => 'Обновите подключение, чтобы установить новое меню команд.',
            ]);
    }

    public function down(): void
    {
        Schema::table('family_trees', function (Blueprint $table): void {
            $table->dropColumn([
                'custom_bot_status',
                'custom_bot_last_error',
                'custom_bot_pending_updates',
                'custom_bot_checked_at',
            ]);
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropUnique(['bot_scope', 'telegram_update_id']);
            $table->dropColumn('bot_scope');
            $table->unique('telegram_update_id');
        });
    }
};
