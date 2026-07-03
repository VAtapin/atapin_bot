<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserMergeService
{
    public function merge(User $source, User $target, ?User $actor = null): User
    {
        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_id' => 'Выберите другую основную учётную запись.']);
        }
        $conflictingTrees = $source->memberships()
            ->whereNotNull('person_id')
            ->get()
            ->filter(function ($sourceMembership) use ($target): bool {
                $targetPersonId = $target->memberships()
                    ->where('tree_id', $sourceMembership->tree_id)
                    ->value('person_id');

                return $targetPersonId
                    && (int) $targetPersonId !== (int) $sourceMembership->person_id;
            });

        if ($conflictingTrees->isNotEmpty()) {
            throw ValidationException::withMessages([
                'target_id' => 'У записей разные привязки к людям в деревьях: '
                    .$conflictingTrees->pluck('tree.name')->filter()->join(', ')
                    .'. Сначала оставьте правильную привязку в разделе «Участники дерева».',
            ]);
        }

        return DB::transaction(function () use ($source, $target, $actor): User {
            DB::table('family_trees')->where('owner_user_id', $source->id)->update(['owner_user_id' => $target->id]);

            foreach ($source->memberships()->get() as $membership) {
                $existing = $target->memberships()->where('tree_id', $membership->tree_id)->first();
                if ($existing) {
                    $roles = ['guest' => 1, 'member' => 2, 'moderator' => 3, 'owner' => 4];
                    $existing->update([
                        'person_id' => $existing->person_id ?: $membership->person_id,
                        'person_linked_at' => $existing->person_linked_at
                            ?: $membership->person_linked_at
                            ?: ($membership->person_id ? now() : null),
                        'role' => ($roles[$membership->role] ?? 0) > ($roles[$existing->role] ?? 0)
                            ? $membership->role
                            : $existing->role,
                        'status' => in_array('approved', [$existing->status, $membership->status], true)
                            ? 'approved'
                            : $existing->status,
                    ]);
                    $membership->delete();
                } else {
                    $membership->update(['user_id' => $target->id]);
                }
            }

            $source->externalIdentities()->update(['user_id' => $target->id]);
            DB::table('telegram_users')->where('user_id', $source->id)->update(['user_id' => $target->id]);
            DB::table('payments')->where('user_id', $source->id)->update(['user_id' => $target->id]);
            DB::table('change_logs')->where('user_id', $source->id)->update(['user_id' => $target->id]);
            DB::table('tree_invitations')->where('created_by_user_id', $source->id)->update(['created_by_user_id' => $target->id]);
            if (DB::getSchemaBuilder()->hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $source->id)->delete();
            }

            $target->update([
                'is_super_admin' => $target->is_super_admin || $source->is_super_admin,
                'super_admin_assigned_by_user_id' => $target->is_super_admin
                    ? $target->super_admin_assigned_by_user_id
                    : ($source->super_admin_assigned_by_user_id ?: $actor?->id),
                'super_admin_assigned_at' => $target->is_super_admin
                    ? $target->super_admin_assigned_at
                    : ($source->super_admin_assigned_at ?: ($source->is_super_admin ? now() : null)),
                'two_factor_enabled' => $target->two_factor_enabled || $source->two_factor_enabled,
            ]);
            $source->update([
                'is_active' => false,
                'is_super_admin' => false,
                'merged_into_user_id' => $target->id,
                'merged_at' => now(),
                'login' => null,
                'last_tree_id' => null,
            ]);

            ChangeLog::query()->create([
                'user_id' => $actor?->id,
                'action' => 'user_accounts_merged',
                'subject_type' => User::class,
                'subject_id' => $target->id,
                'before' => ['source_user_id' => $source->id],
                'after' => ['target_user_id' => $target->id],
            ]);

            return $target->fresh();
        });
    }
}
