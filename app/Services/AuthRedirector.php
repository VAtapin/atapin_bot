<?php

namespace App\Services;

use App\Models\FamilyTree;
use App\Models\TreeMembership;
use App\Models\User;
use App\Support\FamilyTreeUrl;
use Illuminate\Http\RedirectResponse;

class AuthRedirector
{
    public function __construct(private readonly FamilyTreeUrl $familyTreeUrl) {}

    public function redirect(User $user, ?FamilyTree $requestedTree = null): RedirectResponse
    {
        if (! $user->privacy_accepted_at) {
            session()->put('privacy_return_tree_id', $requestedTree?->id);

            return redirect()->route('privacy-consent.show');
        }

        if ($requestedTree) {
            if ($user->is_super_admin) {
                return redirect()->to($this->familyTreeUrl->tree($requestedTree));
            }

            $membership = $user->memberships()
                ->where('tree_id', $requestedTree->id)
                ->first();

            if (! $membership || $membership->status !== 'approved') {
                return redirect()->route('access.pending', ['tree' => $requestedTree->slug]);
            }

            return $this->redirectForMembership($user, $membership);
        }

        if ($user->is_super_admin) {
            return redirect('/admin');
        }

        $memberships = $user->memberships()
            ->with('tree')
            ->where('status', 'approved')
            ->whereHas('tree', fn ($query) => $query->where('status', 'active'))
            ->get();

        if ($memberships->count() === 1) {
            return $this->redirectForMembership($user, $memberships->first());
        }

        if ($memberships->isEmpty()) {
            $pendingTree = $user->memberships()
                ->with('tree')
                ->where('status', 'pending')
                ->latest('id')
                ->first()?->tree;

            return redirect()->route('access.pending', [
                'tree' => $pendingTree?->slug,
            ]);
        }

        return redirect()->route('trees.choose');
    }

    private function redirectForMembership(User $user, TreeMembership $membership): RedirectResponse
    {
        $tree = $membership->tree;
        $user->updateQuietly(['last_tree_id' => $tree->id]);
        session()->put('family_tree_id', $tree->id);

        return in_array($membership->role, ['owner', 'moderator'], true)
            ? redirect('/manage/'.$tree->slug)
            : redirect()->to($this->familyTreeUrl->tree($tree));
    }
}
