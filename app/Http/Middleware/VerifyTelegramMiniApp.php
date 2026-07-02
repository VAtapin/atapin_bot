<?php

namespace App\Http\Middleware;

use App\Models\Person;
use App\Models\TelegramUser;
use App\Models\User;
use App\Services\ExternalIdentityService;
use App\Services\TelegramInitData;
use App\Services\TreeAccessService;
use App\Services\VkLaunchParams;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramMiniApp
{
    public function __construct(
        private readonly TelegramInitData $validator,
        private readonly VkLaunchParams $vkValidator,
        private readonly ExternalIdentityService $identities,
        private readonly TreeAccessService $treeAccess,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $initData = (string) ($request->header('X-Telegram-Init-Data') ?: $request->query('initData', ''));
        $vkLaunchParams = (string) $request->header('X-VK-Launch-Params');
        $tree = $request->attributes->get('familyTree');
        $telegramUser = null;
        $telegramData = null;
        $familyUser = null;

        if ($request->session()->has('family_person_id')) {
            $person = Person::query()
                ->where('web_login_enabled', true)
                ->find($request->session()->get('family_person_id'));

            if ($person) {
                $request->attributes->set('familyPerson', $person);

                return $next($request);
            }

            $request->session()->forget('family_person_id');
        }

        if ($request->session()->has('family_user_id')) {
            $familyUser = User::query()->find($request->session()->get('family_user_id'));
        } elseif ($vkLaunchParams !== '') {
            try {
                $vkData = $this->vkValidator->validate(
                    $vkLaunchParams,
                    (string) config('services.vk.app_secret'),
                );
                $familyUser = $this->identities->resolve('vk', (string) $vkData['vk_user_id'], [
                    'first_name' => 'Пользователь',
                    'last_name' => 'VK',
                    'language_code' => $vkData['vk_language'] ?? null,
                    'launch_params' => $vkData,
                ]);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }
        } elseif ($initData !== '') {
            try {
                $validated = $this->validator->validate(
                    $initData,
                    (string) config('services.telegram.bot_token'),
                );
                $telegramData = $validated['user'];
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }
        } elseif ($request->session()->has('family_telegram_user_id')) {
            $telegramUser = TelegramUser::query()->find(
                $request->session()->get('family_telegram_user_id'),
            );
        } elseif (app()->isLocal() && config('services.telegram.dev_user_id')) {
            $telegramData = [
                'id' => (int) config('services.telegram.dev_user_id'),
                'first_name' => 'Локальный',
                'last_name' => 'Пользователь',
            ];
        } else {
            return response()->json([
                'message' => 'Войдите через Telegram, чтобы открыть семейный архив.',
                'login_url' => config('services.telegram.oidc_client_id')
                    ? route('telegram.login')
                    : null,
                'password_login' => true,
            ], 401);
        }

        if ($telegramData) {
            $adminIds = config('services.telegram.admin_ids', []);
            $isAdmin = in_array((string) $telegramData['id'], $adminIds, true);

            $telegramUser = TelegramUser::query()->updateOrCreate(
                ['telegram_user_id' => $telegramData['id']],
                [
                    'username' => $telegramData['username'] ?? null,
                    'first_name' => $telegramData['first_name'] ?? null,
                    'last_name' => $telegramData['last_name'] ?? null,
                    'language_code' => $telegramData['language_code'] ?? null,
                    'photo_url' => $telegramData['photo_url'] ?? null,
                    'is_bot_admin' => $isAdmin,
                    'current_tree_id' => $tree?->id,
                    'last_seen_at' => now(),
                ],
            );
        }

        if ($telegramUser) {
            $familyUser = $telegramUser->user;

            if (! $familyUser) {
                $familyUser = $this->identities->resolve(
                    'telegram',
                    $telegramUser->telegram_user_id,
                    [
                        'username' => $telegramUser->username,
                        'first_name' => $telegramUser->first_name,
                        'last_name' => $telegramUser->last_name,
                        'photo_url' => $telegramUser->photo_url,
                    ],
                );
                $telegramUser->updateQuietly([
                    'user_id' => $familyUser->id,
                    'current_tree_id' => $tree?->id,
                ]);
            }
        }

        if (! $familyUser) {
            $request->session()->forget('family_telegram_user_id');

            return response()->json([
                'message' => 'Сессия входа устарела. Войдите через Telegram ещё раз.',
                'login_url' => route('telegram.login'),
                'password_login' => true,
            ], 401);
        }

        if ($request->session()->has('family_invitation_token')) {
            try {
                $membership = $this->treeAccess->acceptInvitation(
                    $familyUser,
                    (string) $request->session()->pull('family_invitation_token'),
                );
                $tree = $membership->tree;
            } catch (\Throwable) {
                $membership = $this->treeAccess->membership($familyUser, $tree);
            }
        } else {
            $membership = $this->treeAccess->membership($familyUser, $tree);
        }

        if (
            $telegramUser
            && $telegramUser->isApproved()
            && $membership->status === 'pending'
        ) {
            $membership->update([
                'person_id' => $telegramUser->person_id,
                'role' => $telegramUser->is_bot_admin ? 'moderator' : ($telegramUser->person_id ? 'member' : 'guest'),
                'status' => 'approved',
                'approved_at' => now(),
            ]);
        }

        if (! $familyUser->is_super_admin && $membership->status !== 'approved') {
            return response()->json([
                'message' => 'Доступ ожидает подтверждения администратора семьи.',
                'status' => $membership->status,
            ], 403);
        }

        $request->attributes->set('familyUser', $familyUser);
        $request->attributes->set('treeMembership', $membership);
        if ($telegramUser) {
            $request->attributes->set('telegramUser', $telegramUser);
        }

        return $next($request);
    }
}
