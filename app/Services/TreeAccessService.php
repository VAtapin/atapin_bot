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

            $membership = TreeMembership::query()->updateOrCreate(
                ['tree_id' => $invitation->tree_id, 'user_id' => $user->id],
                [
                    'person_id' => $invitation->person_id,
                    'role' => $invitation->role,
                    'status' => 'approved',
                    'approved_by_user_id' => $invitation->created_by_user_id,
                    'approved_at' => now(),
                ],
            );
            $invitation->increment('uses_count');

            return $membership;
        });
    }
}
