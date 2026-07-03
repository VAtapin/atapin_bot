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
        if ($source->is_super_admin && User::query()->where('is_super_admin', true)->count() <= 1) {
            throw ValidationException::withMessages(['target_id' => 'Нельзя объединить последнего суперадминистратора.']);
        }

        return DB::transaction(function () use ($source, $target, $actor): User {
            DB::table('family_trees')->where('owner_user_id', $source->id)->update(['owner_user_id' => $target->id]);

            foreach ($source->memberships()->get() as $membership) {
                $existing = $target->memberships()->where('tree_id', $membership->tree_id)->first();
                if ($existing) {
                    $roles = ['guest' => 1, 'member' => 2, 'moderator' => 3, 'owner' => 4];
                    $existing->update([
                        'person_id' => $existing->person_id ?: $membership->person_id,
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

            $source->update([
                'is_active' => false,
                'merged_into_user_id' => $target->id,
                'merged_at' => now(),
                'login' => null,
            ]);
            $target->update([
                'is_super_admin' => $target->is_super_admin || $source->is_super_admin,
                'two_factor_enabled' => $target->two_factor_enabled || $source->two_factor_enabled,
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
