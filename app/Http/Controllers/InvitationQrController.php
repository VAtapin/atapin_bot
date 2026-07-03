<?php

namespace App\Http\Controllers;

use App\Models\TreeInvitation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvitationQrController extends Controller
{
    public function __invoke(Request $request, TreeInvitation $invitation): View
    {
        $user = $request->user();
        abort_unless(
            $user?->is_super_admin || $user?->canManageTree($invitation->tree),
            403,
        );
        abort_unless($invitation->invitation_url, 404, 'Ссылку необходимо перевыпустить.');

        return view('public.invitation-qr', [
            'invitation' => $invitation,
            'url' => $invitation->invitation_url,
        ]);
    }
}
