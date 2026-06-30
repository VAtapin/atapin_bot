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
            $table->string('gedcom_id')->nullable()->unique()->after('id');
            $table->string('married_name')->nullable()->after('maiden_name');
            $table->string('death_place')->nullable()->after('death_date');
            $table->string('burial_place')->nullable()->after('death_place');
            $table->text('current_address')->nullable()->after('current_city');
            $table->json('gedcom_data')->nullable()->after('bio');
            $table->timestamp('imported_at')->nullable()->after('gedcom_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['gedcom_id']);
            $table->dropColumn([
                'gedcom_id',
                'married_name',
                'death_place',
                'burial_place',
                'current_address',
                'gedcom_data',
                'imported_at',
            ]);
        });
    }
};
