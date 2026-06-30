<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('telegram_groups', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_chat_id')->unique();
            $table->string('title');
            $table->string('timezone')->default('Europe/Berlin');
            $table->unsignedTinyInteger('birthday_notification_hour')->default(9);
            $table->boolean('notify_birthdays')->default(true);
            $table->boolean('is_active')->default(false)->index();
            $table->date('birthday_last_sent_on')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('telegram_groups');
    }
};
