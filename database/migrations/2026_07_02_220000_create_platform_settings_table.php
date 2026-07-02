<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string');
            $table->string('label');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        DB::table('platform_settings')->insert([
            [
                'key' => 'registration_enabled',
                'value' => '1',
                'type' => 'boolean',
                'label' => 'Разрешить регистрацию новых деревьев',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'support_email',
                'value' => null,
                'type' => 'string',
                'label' => 'Email службы поддержки',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
