<?php

namespace App\Http\Controllers;

use App\Models\TreeInvitation;
use App\Services\AuthRedirector;
use App\Services\TreeAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TreeInvitationController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        abort_unless((bool) preg_match('/^[a-f0-9]{64}$/', $token), 404);

        $invitation = TreeInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
        abort_unless($invitation->isUsable(), 410, 'Приглашение больше не действует.');

        $request->session()->put('family_invitation_token', $token);
        $request->session()->put('family_tree_id', $invitation->tree_id);

        if ($request->user()) {
            $membership = app(TreeAccessService::class)->acceptInvitation($request->user(), $token);
            $request->session()->forget('family_invitation_token');

            return app(AuthRedirector::class)->redirect($request->user(), $membership->tree);
        }

        return redirect()->route('login', ['tree' => $invitation->tree->slug]);
    }
}
