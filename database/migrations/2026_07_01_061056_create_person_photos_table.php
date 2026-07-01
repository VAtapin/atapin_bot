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
        Schema::create('person_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('photo_album_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by_telegram_user_id')
                ->nullable()
                ->constrained('telegram_users')
                ->nullOnDelete();
            $table->string('gedcom_key')->nullable()->unique();
            $table->string('path')->nullable();
            $table->text('source_url')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->date('taken_at')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('gedcom_data')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'photo_album_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_photos');
    }
};
