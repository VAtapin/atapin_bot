<?php

namespace Tests;

use App\Models\FamilyTree;
use App\Models\Plan;
use App\Support\CurrentTree;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (
            $this->name() === 'test_clean_install_has_no_family_trees'
            || ! Schema::hasTable('family_trees')
        ) {
            return;
        }

        $plan = Plan::query()->first();
        $tree = FamilyTree::query()->firstOrCreate(
            ['slug' => 'test-family'],
            [
                'name' => 'Тестовая семья',
                'status' => 'active',
                'plan_id' => $plan?->id,
                'timezone' => 'Europe/Berlin',
            ],
        );
        app(CurrentTree::class)->set($tree);
    }
}
