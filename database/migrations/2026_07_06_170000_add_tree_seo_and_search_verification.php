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
            if (! Schema::hasColumn('family_trees', 'seo_title')) {
                $table->string('seo_title', 180)->nullable();
            }

            if (! Schema::hasColumn('family_trees', 'seo_description')) {
                $table->text('seo_description')->nullable();
            }

            if (! Schema::hasColumn('family_trees', 'og_image_path')) {
                $table->string('og_image_path', 2048)->nullable();
            }
        });

        $now = now();
        foreach ([
            [
                'key' => 'google_site_verification',
                'label' => 'Google Search Console verification',
                'description' => 'Вставьте только значение content из meta-тега Google. Нужен для подтверждения idommoy.com в Search Console.',
                'sort_order' => 410,
            ],
            [
                'key' => 'yandex_site_verification',
                'label' => 'Яндекс Вебмастер verification',
                'description' => 'Вставьте только значение content из meta-тега Яндекса. Нужен для подтверждения сайта в Яндекс Вебмастере.',
                'sort_order' => 420,
            ],
        ] as $setting) {
            DB::table('platform_settings')->updateOrInsert(
                ['key' => $setting['key']],
                [
                    'group' => 'analytics',
                    'value' => null,
                    'type' => 'string',
                    'is_secret' => false,
                    'label' => $setting['label'],
                    'description' => $setting['description'],
                    'sort_order' => $setting['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('family_trees', function (Blueprint $table): void {
            foreach (['seo_title', 'seo_description', 'og_image_path'] as $column) {
                if (Schema::hasColumn('family_trees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        DB::table('platform_settings')
            ->whereIn('key', ['google_site_verification', 'yandex_site_verification'])
            ->delete();
    }
};
