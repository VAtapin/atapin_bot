<?php

namespace App\Services;

use App\Models\FaqItem;
use App\Models\HomePage;
use App\Models\HomeSectionTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HomepageService
{
    public function published(): ?HomePage
    {
        if (! Schema::hasTable('home_pages')) {
            return null;
        }

        return HomePage::query()
            ->where('status', 'published')
            ->where(fn ($query) => $query->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->with([
                'translations',
                'sections' => fn ($query) => $query->where('is_enabled', true),
                'sections.translations',
                'sections.items.translations',
            ])
            ->first();
    }

    public function faqItems(int $limit = 4, array $ids = []): Collection
    {
        return FaqItem::query()
            ->where('is_published', true)
            ->when($ids, fn ($query) => $query->whereIn('id', $ids))
            ->whereHas('category', fn ($query) => $query
                ->where('is_published', true)
                ->whereIn('locale', [app()->getLocale(), 'ru']))
            ->with('category:id,locale,title')
            ->orderByRaw('CASE WHEN faq_category_id IN (SELECT id FROM faq_categories WHERE locale = ?) THEN 0 ELSE 1 END', [app()->getLocale()])
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->unique('question')
            ->values();
    }

    public function preview(): ?HomePage
    {
        if (! Schema::hasTable('home_pages')) {
            return null;
        }

        return HomePage::query()
            ->with([
                'translations',
                'sections',
                'sections.translations',
                'sections.items.translations',
            ])
            ->latest('updated_at')
            ->first();
    }

    public function actionUrl(?string $action, ?string $customUrl = null): ?string
    {
        return match ($action) {
            'register' => route('register'),
            'login' => route('login'),
            'faq' => route('faq'),
            'about' => route('public.page', ['slug' => 'about']),
            'contacts' => route('public.page', ['slug' => 'contacts']),
            'anchor' => $this->safeAnchor($customUrl),
            'custom' => $this->safeUrl($customUrl),
            default => null,
        };
    }

    public function sectionClasses(HomeSectionTranslation $translation): string
    {
        return Str::of((string) $translation->section?->type)
            ->replace('_', '-')
            ->prepend('home-section--')
            ->toString();
    }

    private function safeAnchor(?string $value): ?string
    {
        return is_string($value) && preg_match('/^#[a-z0-9][a-z0-9_-]*$/i', $value) ? $value : null;
    }

    private function safeUrl(?string $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        return in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true) ? $value : null;
    }
}
