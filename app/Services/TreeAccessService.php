<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\TreeInvitation;
use App\Models\TreeMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TreeAccessService
{
    public function membership(User $user, FamilyTree $tree): TreeMembership
    {
        return TreeMembership::query()->firstOrCreate(
            ['tree_id' => $tree->id, 'user_id' => $user->id],
            ['role' => 'guest', 'status' => 'pending'],
        );
    }

    public function acceptInvitation(User $user, string $plainToken): TreeMembership
    {
        return DB::transaction(function () use ($user, $plainToken): TreeMembership {
            $invitation = TreeInvitation::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $invitation || ! $invitation->isUsable()) {
                throw ValidationException::withMessages([
                    'invitation' => 'Приглашение недействительно или уже использовано.',
                ]);
            }

            $membership = TreeMembership::query()->firstOrNew([
                'tree_id' => $invitation->tree_id,
                'user_id' => $user->id,
            ]);
            $rank = ['guest' => 0, 'member' => 1, 'moderator' => 2, 'owner' => 3];
            $role = ($rank[$membership->role] ?? -1) >= ($rank[$invitation->role] ?? 0)
                ? $membership->role
                : $invitation->role;
            $membership->fill([
                'person_id' => $membership->person_id ?: $invitation->person_id,
                'role' => $role,
                'status' => 'approved',
                'approved_by_user_id' => $invitation->created_by_user_id,
                'approved_at' => $membership->approved_at ?: now(),
            ])->save();
            $invitation->increment('uses_count');

            app(AnalyticsService::class)->record(
                'invite_accepted',
                user: $user,
                tree: $membership->tree,
                parameters: [
                    'tree_id' => $membership->tree_id,
                    'invitation_id' => $invitation->id,
                ],
                deduplicationKey: "invite_accepted:{$invitation->id}:user:{$user->id}",
            );

            return $membership;
        });
    }
}
