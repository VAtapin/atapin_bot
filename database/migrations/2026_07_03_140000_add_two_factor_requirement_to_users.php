<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('two_factor_required')
                ->default(false)
                ->after('two_factor_enabled')
                ->index();
        });

        DB::table('users')->whereNull('two_factor_confirmed_at')->update([
            'two_factor_enabled' => false,
        ]);
        DB::table('users')->where('is_super_admin', true)->update([
            'two_factor_required' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('two_factor_required');
        });
    }
};
