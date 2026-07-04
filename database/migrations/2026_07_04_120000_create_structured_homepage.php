<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('home_pages')) {
            Schema::create('home_pages', function (Blueprint $table): void {
                $table->id();
                $table->string('status', 20)->default('published')->index();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('home_page_translations')) {
            Schema::create('home_page_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('home_page_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('meta_title')->nullable();
                $table->text('meta_description')->nullable();
                $table->string('og_image_path')->nullable();
                $table->string('og_image_alt')->nullable();
                $table->timestamps();
                $table->unique(['home_page_id', 'locale']);
            });
        }

        if (! Schema::hasTable('home_sections')) {
            Schema::create('home_sections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('home_page_id')->constrained()->cascadeOnDelete();
                $table->string('type', 40)->index();
                $table->string('image_path')->nullable();
                $table->string('image_position', 20)->default('right');
                $table->json('settings')->nullable();
                $table->boolean('is_enabled')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['home_page_id', 'is_enabled', 'sort_order']);
            });
        }

        if (! Schema::hasTable('home_section_translations')) {
            Schema::create('home_section_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('home_section_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('eyebrow')->nullable();
                $table->string('title')->nullable();
                $table->text('lead')->nullable();
                $table->longText('content')->nullable();
                $table->string('image_alt')->nullable();
                $table->string('primary_label')->nullable();
                $table->string('primary_action', 30)->nullable();
                $table->string('primary_url')->nullable();
                $table->string('secondary_label')->nullable();
                $table->string('secondary_action', 30)->nullable();
                $table->string('secondary_url')->nullable();
                $table->timestamps();
                $table->unique(['home_section_id', 'locale']);
            });
        }

        if (! Schema::hasTable('home_section_items')) {
            Schema::create('home_section_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('home_section_id')->constrained()->cascadeOnDelete();
                $table->string('icon', 100)->nullable();
                $table->string('image_path')->nullable();
                $table->json('settings')->nullable();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['home_section_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('home_section_item_translations')) {
            Schema::create('home_section_item_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('home_section_item_id')->constrained()->cascadeOnDelete();
                $table->string('locale', 10);
                $table->string('title')->nullable();
                $table->text('text')->nullable();
                $table->string('image_alt')->nullable();
                $table->string('button_label')->nullable();
                $table->string('button_action', 30)->nullable();
                $table->string('button_url')->nullable();
                $table->timestamps();
                $table->unique(['home_section_item_id', 'locale']);
            });
        }

        $this->seedHomepage();
    }

    public function down(): void
    {
        Schema::dropIfExists('home_section_item_translations');
        Schema::dropIfExists('home_section_items');
        Schema::dropIfExists('home_section_translations');
        Schema::dropIfExists('home_sections');
        Schema::dropIfExists('home_page_translations');
        Schema::dropIfExists('home_pages');
    }

    private function seedHomepage(): void
    {
        if (DB::table('home_pages')->exists()) {
            return;
        }

        $now = now();
        $pageId = DB::table('home_pages')->insertGetId([
            'status' => 'published',
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $content = [];
        foreach (['ru', 'de', 'en', 'uk'] as $locale) {
            $translations = require lang_path("{$locale}/public.php");
            $content[$locale] = $translations;
            DB::table('home_page_translations')->insert([
                'home_page_id' => $pageId,
                'locale' => $locale,
                'meta_title' => data_get($translations, 'meta.home_title'),
                'meta_description' => data_get($translations, 'meta.home_description'),
                'og_image_alt' => data_get($translations, 'meta.image_alt'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $definitions = [
            ['hero', 10, 'eyebrow', 'title', 'lead', null, 'create', 'register', 'how_link', 'anchor', '#how-it-works'],
            ['story', 20, null, null, null, 'story'],
            ['features', 30, null, 'features_title', 'features_lead'],
            ['how_it_works', 40, null, 'how_title', 'how_lead'],
            ['privacy', 50, null, 'privacy_title', null, 'privacy_text'],
            ['pricing', 60, null, 'plans_title', 'plans_lead'],
            ['faq_teaser', 70, null, 'questions_title', 'questions_lead', null, 'open_faq', 'faq'],
            ['final_cta', 80, null, 'cta_title', null, 'cta_text', 'create', 'register', 'about', 'about'],
        ];

        foreach ($definitions as $definition) {
            [$type, $sort, $eyebrowKey, $titleKey, $leadKey, $contentKey] = array_pad($definition, 11, null);
            $sectionId = DB::table('home_sections')->insertGetId([
                'home_page_id' => $pageId,
                'type' => $type,
                'image_position' => 'right',
                'is_enabled' => true,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($content as $locale => $translations) {
                $home = $translations['home'];
                DB::table('home_section_translations')->insert([
                    'home_section_id' => $sectionId,
                    'locale' => $locale,
                    'eyebrow' => $eyebrowKey ? data_get($home, $eyebrowKey) : null,
                    'title' => $titleKey ? data_get($home, $titleKey) : null,
                    'lead' => $leadKey ? data_get($home, $leadKey) : null,
                    'content' => $contentKey ? '<p>'.e((string) data_get($home, $contentKey)).'</p>' : null,
                    'primary_label' => isset($definition[6]) ? data_get($home, $definition[6]) : null,
                    'primary_action' => $definition[7] ?? null,
                    'primary_url' => null,
                    'secondary_label' => isset($definition[8]) ? data_get($home, $definition[8]) : null,
                    'secondary_action' => $definition[9] ?? null,
                    'secondary_url' => $definition[10] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $itemKey = match ($type) {
                'hero' => 'trust',
                'features' => 'features',
                'how_it_works' => 'steps',
                default => null,
            };
            if (! $itemKey) {
                continue;
            }

            $maxItems = max(array_map(
                fn (array $translations): int => count(data_get($translations, "home.{$itemKey}", [])),
                $content,
            ));
            for ($index = 0; $index < $maxItems; $index++) {
                $ruItem = data_get($content['ru'], "home.{$itemKey}.{$index}");
                $itemId = DB::table('home_section_items')->insertGetId([
                    'home_section_id' => $sectionId,
                    'icon' => is_array($ruItem) ? ($ruItem['icon'] ?? null) : null,
                    'sort_order' => ($index + 1) * 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($content as $locale => $translations) {
                    $item = data_get($translations, "home.{$itemKey}.{$index}");
                    DB::table('home_section_item_translations')->insert([
                        'home_section_item_id' => $itemId,
                        'locale' => $locale,
                        'title' => is_array($item) ? ($item['title'] ?? null) : (string) $item,
                        'text' => is_array($item) ? ($item['text'] ?? null) : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }
};
