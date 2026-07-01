<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_login_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_login_tokens');
    }
};
