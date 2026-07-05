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

            if (! $invitation) {
                throw ValidationException::withMessages([
                    'invitation' => 'Приглашение недействительно или уже использовано.',
                ]);
            }

            $membership = TreeMembership::query()->where([
                'tree_id' => $invitation->tree_id,
                'user_id' => $user->id,
            ])->first();

            $rank = ['guest' => 0, 'member' => 1, 'moderator' => 2, 'owner' => 3];

            if ($membership?->status === 'approved') {
                // Owners and moderators commonly open links to test them.
                // Their visit must never spend somebody else's invitation.
                if ($user->canManageTree($invitation->tree)) {
                    return $membership;
                }

                $alreadyHasRole = ($rank[$membership->role] ?? -1)
                    >= ($rank[$invitation->role] ?? 0);
                $alreadyLinked = ! $invitation->person_id
                    || (int) $membership->person_id === (int) $invitation->person_id;

                if ($alreadyHasRole && $alreadyLinked) {
                    return $membership;
                }

                if (
                    $membership->person_id
                    && $invitation->person_id
                    && (int) $membership->person_id !== (int) $invitation->person_id
                ) {
                    throw ValidationException::withMessages([
                        'invitation' => 'Приглашение предназначено для другого человека.',
                    ]);
                }
            }

            if (! $invitation->isUsable()) {
                throw ValidationException::withMessages([
                    'invitation' => 'Приглашение недействительно или уже использовано.',
                ]);
            }

            $membership ??= new TreeMembership([
                'tree_id' => $invitation->tree_id,
                'user_id' => $user->id,
            ]);
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
