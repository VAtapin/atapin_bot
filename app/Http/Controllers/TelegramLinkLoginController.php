<?php

namespace App\Http\Controllers;

use App\Models\FamilyTree;
use App\Models\TelegramLoginToken;
use App\Services\AuthRedirector;
use App\Support\SafeReturnUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TelegramLinkLoginController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        abort_unless((bool) preg_match('/^[a-f0-9]{64}$/', $token), 404);

        $loginToken = DB::transaction(function () use ($token): TelegramLoginToken {
            $loginToken = TelegramLoginToken::query()
                ->with('telegramUser')
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->firstOrFail();

            abort_if(
                $loginToken->used_at
                || $loginToken->expires_at->isPast()
                || ! $loginToken->telegramUser->isApproved(),
                403,
                'Ссылка входа недействительна или уже использована.',
            );

            $loginToken->update(['used_at' => now()]);

            return $loginToken;
        });

        $request->session()->regenerate();
        $request->session()->put('family_telegram_user_id', $loginToken->telegram_user_id);
        $request->session()->put('family_user_id', $loginToken->telegramUser->user_id);
        $request->session()->put('family_tree_id', $loginToken->telegramUser->current_tree_id);
        $request->session()->forget('family_person_id');
        if ($loginToken->telegramUser->user) {
            Auth::login($loginToken->telegramUser->user);
        }
        $loginToken->telegramUser->updateQuietly(['last_web_login_at' => now()]);

        $tree = $loginToken->telegramUser->current_tree_id
            ? FamilyTree::query()->find($loginToken->telegramUser->current_tree_id)
            : null;

        if (
            $request->attributes->get('customDomainTree')
            && $tree
            && (int) $request->attributes->get('customDomainTree')->id === (int) $tree->id
        ) {
            return redirect()->to(SafeReturnUrl::path($request->query('return')) ?: '/');
        }

        return $loginToken->telegramUser->user
            ? app(AuthRedirector::class)->redirect($loginToken->telegramUser->user, $tree)
            : redirect()->route('access.pending', ['tree' => $tree?->slug]);
    }
}
