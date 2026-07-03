<?php

namespace App\Console\Commands;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Services\ImageThumbnailService;
use App\Services\TreeStorageService;
use App\Support\CurrentTree;
use Illuminate\Console\Command;

class GenerateMediaThumbnails extends Command
{
    protected $signature = 'media:generate-thumbnails {--tree= : ID or slug of one family tree}';

    protected $description = 'Generate missing thumbnails for private family media';

    public function handle(ImageThumbnailService $thumbnails): int
    {
        $trees = FamilyTree::query()
            ->when($this->option('tree'), function ($query): void {
                $value = $this->option('tree');
                $query->where(fn ($query) => $query
                    ->where('id', $value)
                    ->orWhere('slug', $value));
            })
            ->get();

        $count = 0;
        foreach ($trees as $tree) {
            app(CurrentTree::class)->set($tree);
            Person::query()
                ->whereNotNull('photo_path')
                ->each(function (Person $person) use ($thumbnails, &$count): void {
                    if ($thumbnails->ensureForPerson($person)) {
                        $count++;
                    }
                });
            PersonPhoto::query()
                ->whereNotNull('path')
                ->each(function (PersonPhoto $photo) use ($thumbnails, &$count): void {
                    if ($thumbnails->ensureForPhoto($photo)) {
                        $count++;
                    }
                });
            app(TreeStorageService::class)->recalculate($tree->fresh('plan'));
        }

        $this->info("Подготовлено миниатюр: {$count}");

        return self::SUCCESS;
    }
}
