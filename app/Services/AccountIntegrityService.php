<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountIntegrityService
{
    /**
     * @return array{issues: array<int, array<string, mixed>>, total: int}
     */
    public function inspect(): array
    {
        $issues = [];
        DB::table('telegram_users as telegram')
            ->join('users', 'users.id', '=', 'telegram.user_id')
            ->whereNotNull('users.merged_into_user_id')
            ->get(['telegram.id', 'users.merged_into_user_id'])
            ->each(function ($row) use (&$issues): void {
                $issues[] = [
                    'type' => 'telegram_on_merged_user',
                    'message' => "Telegram #{$row->id} остался у объединённой записи; цель #{$row->merged_into_user_id}.",
                ];
            });
        DB::table('tree_memberships as memberships')
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->whereNotNull('users.merged_into_user_id')
            ->get(['memberships.id', 'memberships.tree_id', 'users.merged_into_user_id'])
            ->each(function ($row) use (&$issues): void {
                $issues[] = [
                    'type' => 'membership_on_merged_user',
                    'message' => "Членство #{$row->id} дерева #{$row->tree_id} осталось у объединённой записи; цель #{$row->merged_into_user_id}.",
                ];
            });
        DB::table('tree_memberships')
            ->whereNotNull('person_id')
            ->select('tree_id', 'person_id', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('tree_id', 'person_id')
            ->having('aggregate', '>', 1)
            ->get()
            ->each(function ($row) use (&$issues): void {
                $issues[] = [
                    'type' => 'person_link_conflict',
                    'message' => "Человек #{$row->person_id} дерева #{$row->tree_id} привязан к {$row->aggregate} учётным записям.",
                ];
            });

        return ['issues' => $issues, 'total' => count($issues)];
    }

    public function repairMergedReferences(): int
    {
        $count = 0;
        User::query()
            ->whereNotNull('merged_into_user_id')
            ->each(function (User $source) use (&$count): void {
                $targetId = $source->merged_into_user_id;
                $count += DB::table('telegram_users')->where('user_id', $source->id)->update(['user_id' => $targetId]);
                $count += DB::table('external_identities')->where('user_id', $source->id)->update(['user_id' => $targetId]);

                DB::table('tree_memberships')
                    ->where('user_id', $source->id)
                    ->get()
                    ->each(function ($membership) use ($targetId, &$count): void {
                        $target = DB::table('tree_memberships')
                            ->where('tree_id', $membership->tree_id)
                            ->where('user_id', $targetId)
                            ->first();
                        if (! $target) {
                            $count += DB::table('tree_memberships')
                                ->where('id', $membership->id)
                                ->update(['user_id' => $targetId]);

                            return;
                        }
                        if (
                            $target->person_id
                            && $membership->person_id
                            && (int) $target->person_id !== (int) $membership->person_id
                        ) {
                            return;
                        }
                        DB::table('tree_memberships')->where('id', $target->id)->update([
                            'person_id' => $target->person_id ?: $membership->person_id,
                            'status' => in_array('approved', [$target->status, $membership->status], true)
                                ? 'approved'
                                : $target->status,
                            'updated_at' => now(),
                        ]);
                        DB::table('tree_memberships')->where('id', $membership->id)->delete();
                        $count++;
                    });

                if (DB::getSchemaBuilder()->hasTable('sessions')) {
                    DB::table('sessions')->where('user_id', $source->id)->delete();
                }
            });

        return $count;
    }
}
