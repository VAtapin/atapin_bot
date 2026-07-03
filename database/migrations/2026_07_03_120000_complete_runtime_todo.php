<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_trees', function (Blueprint $table): void {
            $table->foreignId('start_person_id')
                ->nullable()
                ->after('owner_user_id')
                ->constrained('people')
                ->nullOnDelete();
            $table->string('domain_status', 30)->default('not_configured')->after('primary_domain')->index();
            $table->string('domain_verification_token', 80)->nullable()->after('domain_status');
            $table->timestamp('domain_verified_at')->nullable()->after('domain_verification_token');
            $table->string('domain_ssl_status', 30)->default('unknown')->after('domain_verified_at');
            $table->timestamp('domain_checked_at')->nullable()->after('domain_ssl_status');
            $table->text('domain_last_error')->nullable()->after('domain_checked_at');
            $table->index('primary_domain');
        });

        Schema::table('tree_memberships', function (Blueprint $table): void {
            $table->timestamp('person_linked_at')->nullable()->after('person_id');
            $table->foreignId('person_linked_by_user_id')
                ->nullable()
                ->after('person_linked_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['tree_id', 'person_id']);
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->string('photo_thumbnail_path')->nullable()->after('photo_path');
        });

        Schema::table('person_photos', function (Blueprint $table): void {
            $table->string('thumbnail_path')->nullable()->after('path');
            $table->unsignedBigInteger('thumbnail_file_size')->default(0)->after('file_size');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('super_admin_assigned_by_user_id')
                ->nullable()
                ->after('is_super_admin')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('super_admin_assigned_at')->nullable()->after('super_admin_assigned_by_user_id');
        });
        DB::table('users')->where('is_super_admin', true)->update([
            'two_factor_enabled' => true,
            'super_admin_assigned_at' => now(),
        ]);

        Schema::create('congratulations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tree_id')->constrained('family_trees')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sender_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('recipient_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('partnership_id')->nullable()->constrained('partnerships')->nullOnDelete();
            $table->string('occasion', 30);
            $table->text('message');
            $table->string('site_status', 20)->default('delivered')->index();
            $table->string('telegram_status', 20)->default('not_available')->index();
            $table->text('telegram_error')->nullable();
            $table->timestamp('telegram_delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['tree_id', 'recipient_person_id', 'created_at'], 'congratulations_recipient_index');
        });

        Schema::create('smtp_test_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient');
            $table->string('status', 30)->index();
            $table->string('stage', 40)->nullable();
            $table->string('message_id')->nullable();
            $table->json('diagnostics')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('system_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('status', 20)->default('ok')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('deleted_tree_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('original_tree_id')->index();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('reason')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('deleted_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_tree_audits');
        Schema::dropIfExists('system_heartbeats');
        Schema::dropIfExists('smtp_test_logs');
        Schema::dropIfExists('congratulations');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('super_admin_assigned_by_user_id');
            $table->dropColumn('super_admin_assigned_at');
        });

        Schema::table('person_photos', function (Blueprint $table): void {
            $table->dropColumn(['thumbnail_path', 'thumbnail_file_size']);
        });

        Schema::table('people', function (Blueprint $table): void {
            $table->dropColumn('photo_thumbnail_path');
        });

        Schema::table('tree_memberships', function (Blueprint $table): void {
            $table->dropIndex(['tree_id', 'person_id']);
            $table->dropConstrainedForeignId('person_linked_by_user_id');
            $table->dropColumn('person_linked_at');
        });

        Schema::table('family_trees', function (Blueprint $table): void {
            $table->dropIndex(['primary_domain']);
            $table->dropConstrainedForeignId('start_person_id');
            $table->dropColumn([
                'domain_status',
                'domain_verification_token',
                'domain_verified_at',
                'domain_ssl_status',
                'domain_checked_at',
                'domain_last_error',
            ]);
        });
    }
};
