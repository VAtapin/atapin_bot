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
        Schema::create('partnerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_one_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('partner_two_id')->constrained('people')->cascadeOnDelete();
            $table->string('status', 20)->default('married')->index();
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();
            $table->string('place')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['partner_one_id', 'partner_two_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partnerships');
    }
};
