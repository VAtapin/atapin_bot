<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $familyTables = [
            'people',
            'parent_children',
            'partnerships',
            'family_events',
            'photo_albums',
            'person_photos',
        ];

        DB::table('family_trees')
            ->orderBy('id')
            ->get()
            ->each(function (object $tree) use ($familyTables): void {
                $settings = json_decode($tree->settings ?: '[]', true) ?: [];

                if (! ($settings['is_legacy_tree'] ?? false)) {
                    return;
                }

                $containsFamilyData = collect($familyTables)
                    ->contains(fn (string $table): bool => DB::table($table)
                        ->where('tree_id', $tree->id)
                        ->exists());

                if ($containsFamilyData) {
                    return;
                }

                DB::table('family_trees')->where('id', $tree->id)->delete();
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('login')->nullable()->unique()->after('email');
            $table->foreignId('last_tree_id')
                ->nullable()
                ->after('two_factor_enabled')
                ->constrained('family_trees')
                ->nullOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('provider_customer_reference')->nullable()->after('provider_reference');
            $table->timestamp('next_billing_at')->nullable()->after('ends_at');
            $table->timestamp('grace_ends_at')->nullable()->after('next_billing_at');
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_customer_reference',
                'next_billing_at',
                'grace_ends_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_tree_id');
            $table->dropUnique(['login']);
            $table->dropColumn('login');
        });
    }
};
