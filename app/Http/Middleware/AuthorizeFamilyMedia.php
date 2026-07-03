<?php

namespace App\Http\Middleware;

use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\TelegramUser;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeFamilyMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $photoId = $request->route('photo');
        $personId = $request->route('person');
        $treeId = $photoId
            ? PersonPhoto::withoutGlobalScope('family_tree')->whereKey($photoId)->value('tree_id')
            : Person::withoutGlobalScope('family_tree')->whereKey($personId)->value('tree_id');
        abort_unless($treeId, 404);

        $user = $request->user();
        if (! $user && $request->session()->has('family_user_id')) {
            $user = User::query()->find($request->session()->get('family_user_id'));
        }
        if (! $user && $request->session()->has('family_telegram_user_id')) {
            $user = TelegramUser::query()
                ->find($request->session()->get('family_telegram_user_id'))
                ?->user;
        }
        abort_unless($user, 401);

        $allowed = Cache::remember(
            "media-access:{$user->id}:{$treeId}",
            now()->addMinutes(5),
            fn (): bool => $user->is_super_admin || $user->memberships()
                ->where('tree_id', $treeId)
                ->where('status', 'approved')
                ->exists(),
        );
        abort_unless($allowed, 403);

        $key = "media-download:{$user->id}:{$treeId}";
        abort_if(RateLimiter::tooManyAttempts($key, 3000), 429, 'Слишком много загрузок медиа.');
        RateLimiter::hit($key, 300);

        return $next($request);
    }
}
