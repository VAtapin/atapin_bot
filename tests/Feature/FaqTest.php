<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_faq_has_search_categories_and_quick_start(): void
    {
        $this->get('/faq?lang=ru')
            ->assertOk()
            ->assertSee('Быстрый старт')
            ->assertSee('faq-search', false)
            ->assertSee('С чего начать новое семейное дерево?');
    }

    public function test_unpublished_answer_is_hidden(): void
    {
        $item = FaqItem::query()->firstOrFail();
        $item->update(['is_published' => false]);

        $this->get('/faq?lang=ru')
            ->assertOk()
            ->assertDontSee($item->question);
    }
}
