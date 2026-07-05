<?php

namespace App\Console\Commands;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Support\CurrentTree;
use Illuminate\Console\Command;

class PurgeUnassociatedPhotos extends Command
{
    protected $signature = 'family:purge-unassociated-photos
        {tree : ID or slug of the family tree}
        {--force : Delete without an interactive confirmation}';

    protected $description = 'Delete GEDCOM photos stored in the synthetic “Unassociated photos” record';

    public function handle(CurrentTree $currentTree): int
    {
        $value = (string) $this->argument('tree');
        $tree = FamilyTree::query()
            ->where(fn ($query) => $query->where('slug', $value)
                ->when(ctype_digit($value), fn ($query) => $query->orWhereKey((int) $value)))
            ->first();

        if (! $tree) {
            $this->error('Family tree not found.');

            return self::FAILURE;
        }

        $currentTree->set($tree);
        $holders = Person::query()
            ->where(fn ($query) => $query
                ->where('gedcom_id', 'I88888888')
                ->orWhereRaw('LOWER(first_name) = ?', ['unassociated photos']))
            ->with('photos')
            ->get();
        $count = $holders->sum(fn (Person $person): int => $person->photos->count());

        if ($count === 0) {
            $this->info('No unassociated GEDCOM photos found.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Delete {$count} unassociated photos from {$tree->name}?")) {
            return self::SUCCESS;
        }

        $holders->each(fn (Person $person) => $person->photos->each->delete());
        $this->info("Deleted {$count} unassociated photos.");

        return self::SUCCESS;
    }
}
