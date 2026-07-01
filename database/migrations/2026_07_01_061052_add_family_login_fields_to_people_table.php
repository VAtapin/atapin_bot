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
        Schema::table('people', function (Blueprint $table) {
            $table->string('login')->nullable()->unique()->after('gedcom_id');
            $table->string('password')->nullable()->after('login');
            $table->boolean('web_login_enabled')->default(false)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['login']);
            $table->dropColumn(['login', 'password', 'web_login_enabled']);
        });
    }
};
