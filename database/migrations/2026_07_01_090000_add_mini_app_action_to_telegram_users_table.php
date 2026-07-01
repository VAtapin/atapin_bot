<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->json('mini_app_action')->nullable()->after('pending_command');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table): void {
            $table->dropColumn('mini_app_action');
        });
    }
};
