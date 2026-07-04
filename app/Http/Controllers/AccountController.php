<?php

namespace App\Http\Controllers;

use App\Models\ExternalIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        return view('public.account', [
            'user' => $request->user()->load('externalIdentities'),
        ]);
    }

    public function unlink(Request $request, ExternalIdentity $identity): RedirectResponse
    {
        abort_unless($identity->user_id === $request->user()->id, 403);
        abort_if(
            $request->user()->externalIdentities()->count() <= 1
            && blank($request->user()->password),
            422,
            __('public.messages.identity_last'),
        );
        $identity->delete();

        return back()->with('status', __('public.messages.identity_removed'));
    }
}
