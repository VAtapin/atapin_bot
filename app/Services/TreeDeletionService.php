<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\FamilyTree;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TreeDeletionService
{
    public function schedule(FamilyTree $tree, User $actor, ?string $reason = null): FamilyTree
    {
        abort_unless($actor->is_super_admin || $tree->owner_user_id === $actor->id, 403);
        if (! $tree->isDeletionScheduled()) {
            app(TreeArchiveService::class)->create($tree, $actor, 'before_deletion');
        }

        $tree->update([
            'status' => 'deleting',
            'deletion_scheduled_at' => now()->addDays(30),
            'deletion_requested_by_user_id' => $actor->id,
            'deletion_reason' => $reason,
        ]);
        $this->log($tree, $actor, 'tree_deletion_scheduled');

        return $tree->fresh();
    }

    public function cancel(FamilyTree $tree, User $actor): FamilyTree
    {
        abort_unless($actor->is_super_admin || $tree->owner_user_id === $actor->id, 403);
        $tree->update([
            'status' => 'active',
            'deletion_scheduled_at' => null,
            'deletion_requested_by_user_id' => null,
            'deletion_reason' => null,
        ]);
        $this->log($tree, $actor, 'tree_deletion_cancelled');

        return $tree->fresh();
    }

    public function purgeExpired(): int
    {
        $count = 0;
        FamilyTree::query()
            ->where('status', 'deleting')
            ->where('deletion_scheduled_at', '<=', now())
            ->each(function (FamilyTree $tree) use (&$count): void {
                $this->purgeFamilyData($tree);
                $tree->updateQuietly(['status' => 'deleted']);
                $tree->delete();
                $count++;
            });

        return $count;
    }

    private function purgeFamilyData(FamilyTree $tree): void
    {
        Storage::disk('public')->deleteDirectory("trees/{$tree->id}");
        DB::transaction(function () use ($tree): void {
            foreach ([
                'family_events',
                'person_photos',
                'photo_albums',
                'parent_children',
                'partnerships',
                'telegram_groups',
                'data_issues',
                'tree_invitations',
                'tree_imports',
                'settings',
                'people',
            ] as $table) {
                DB::table($table)->where('tree_id', $tree->id)->delete();
            }
            DB::table('tree_memberships')
                ->where('tree_id', $tree->id)
                ->where('role', '!=', 'owner')
                ->delete();
        });
    }

    private function log(FamilyTree $tree, User $actor, string $action): void
    {
        ChangeLog::query()->create([
            'tree_id' => $tree->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => FamilyTree::class,
            'subject_id' => $tree->id,
            'after' => ['deletion_scheduled_at' => $tree->deletion_scheduled_at?->toIso8601String()],
        ]);
    }
}
