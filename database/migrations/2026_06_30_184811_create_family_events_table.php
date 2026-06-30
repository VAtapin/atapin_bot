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
        Schema::create('family_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('type', 30)->default('other')->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('event_date')->index();
            $table->time('event_time')->nullable();
            $table->string('place')->nullable();
            $table->boolean('is_annual')->default(false);
            $table->boolean('is_published')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('family_events');
    }
};
