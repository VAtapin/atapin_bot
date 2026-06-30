<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        collect([
            ['key' => 'family_name', 'label' => 'Название семьи', 'value' => 'Наша семья'],
            ['key' => 'welcome_text', 'label' => 'Приветствие', 'value' => 'Добро пожаловать в семейный архив!'],
        ])->each(fn (array $setting) => Setting::query()->firstOrCreate(
            ['key' => $setting['key']],
            [...$setting, 'type' => 'string'],
        ));
    }
}
