<?php

namespace App\Http\Controllers;

use App\Services\TelegramAccountLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TelegramAccountLinkController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramAccountLinkService $links,
    ): RedirectResponse {
        if ($request->user()->externalIdentities()->where('provider', 'telegram')->exists()) {
            return redirect()->route('account')->with('status', __('public.messages.telegram_connected'));
        }

        return redirect()->away($links->createDeepLink($request->user()));
    }
}
