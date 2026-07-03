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
                $actor = $tree->deletionRequestedBy ?: $tree->owner;
                if ($actor) {
                    $this->purgeNow($tree, $actor, $tree->deletion_reason);
                } else {
                    $this->purgeFamilyData($tree, null, $tree->deletion_reason);
                }
                $count++;
            });

        return $count;
    }

    public function purgeNow(FamilyTree $tree, User $actor, ?string $reason = null): void
    {
        abort_unless($actor->is_super_admin || $tree->owner_user_id === $actor->id, 403);
        $this->purgeFamilyData($tree, $actor, $reason);
    }

    private function purgeFamilyData(FamilyTree $tree, ?User $actor, ?string $reason = null): void
    {
        app(CustomTelegramBotService::class)->disconnect($tree);
        $treeId = $tree->id;
        $summary = [
            'people' => DB::table('people')->where('tree_id', $tree->id)->count(),
            'photos' => DB::table('person_photos')->where('tree_id', $tree->id)->count(),
            'members' => DB::table('tree_memberships')->where('tree_id', $tree->id)->count(),
            'payments' => DB::table('payments')->where('tree_id', $tree->id)->count(),
            'payment_total' => DB::table('payments')->where('tree_id', $tree->id)->sum('amount'),
            'accounting_records' => DB::table('payments')
                ->where('tree_id', $tree->id)
                ->get([
                    'provider',
                    'provider_reference',
                    'status',
                    'amount',
                    'currency',
                    'description',
                    'paid_at',
                    'refunded_at',
                    'created_at',
                ])
                ->map(fn ($payment): array => (array) $payment)
                ->all(),
        ];

        DB::transaction(function () use ($tree, $actor, $reason, $summary): void {
            DB::table('deleted_tree_audits')->insert([
                'original_tree_id' => $tree->id,
                'deleted_by_user_id' => $actor?->id,
                'name' => $tree->name,
                'slug' => $tree->slug,
                'reason' => $reason,
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE),
                'deleted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tree->updateQuietly(['start_person_id' => null]);
            $tree->forceDelete();
        });
        Storage::disk('public')->deleteDirectory("trees/{$treeId}");
        Storage::disk('local')->deleteDirectory("tree-backups/{$treeId}");
        Storage::disk('local')->deleteDirectory("tree-imports/{$treeId}");
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
