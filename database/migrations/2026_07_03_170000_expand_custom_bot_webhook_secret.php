<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_trees', function (Blueprint $table): void {
            $table->text('custom_bot_webhook_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('family_trees', function (Blueprint $table): void {
            $table->string('custom_bot_webhook_secret')->nullable()->change();
        });
    }
};
