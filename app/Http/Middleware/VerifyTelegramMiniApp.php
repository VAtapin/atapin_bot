<?php

namespace App\Http\Middleware;

use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Models\User;
use App\Services\ExternalIdentityService;
use App\Services\TelegramInitData;
use App\Services\TreeAccessService;
use App\Services\VkLaunchParams;
use App\Support\CurrentTree;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyTelegramMiniApp
{
    public function __construct(
        private readonly TelegramInitData $validator,
        private readonly VkLaunchParams $vkValidator,
        private readonly ExternalIdentityService $identities,
        private readonly TreeAccessService $treeAccess,
        private readonly CurrentTree $currentTree,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tree = $this->requestedTree($request);
        $initData = (string) ($request->header('X-Telegram-Init-Data') ?: $request->query('initData', ''));
        $vkLaunchParams = (string) $request->header('X-VK-Launch-Params');
        $telegramUser = null;
        $familyUser = $request->user()
            ?: ($request->session()->has('family_user_id')
                ? User::query()->find($request->session()->get('family_user_id'))
                : null);
        $telegramData = null;

        if ($vkLaunchParams !== '') {
            try {
                $vkData = $this->vkValidator->validate(
                    $vkLaunchParams,
                    (string) config('services.vk.app_secret'),
                );
                $familyUser = $this->identities->resolve(
                    'vk',
                    (string) $vkData['vk_user_id'],
                    [
                        'first_name' => 'Пользователь',
                        'last_name' => 'VK',
                        'language_code' => $vkData['vk_language'] ?? null,
                        'launch_params' => $vkData,
                    ],
                    $familyUser,
                );
            } catch (Throwable $exception) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }
        } elseif ($initData !== '') {
            try {
                $validated = $this->validator->validate(
                    $initData,
                    (string) (
                        $tree?->custom_bot_verified_at && $tree?->custom_bot_token
                            ? $tree->custom_bot_token
                            : config('services.telegram.bot_token')
                    ),
                );
                $telegramData = $validated['user'];
                $telegramUser = TelegramUser::query()
                    ->where('telegram_user_id', $telegramData['id'])
                    ->first();
                $tree ??= $telegramUser?->currentTree;
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 401);
            }
        } elseif ($request->session()->has('family_telegram_user_id')) {
            $telegramUser = TelegramUser::query()->find(
                $request->session()->get('family_telegram_user_id'),
            );
            $tree ??= $telegramUser?->currentTree;
        } elseif (app()->isLocal() && config('services.telegram.dev_user_id')) {
            $telegramData = [
                'id' => (int) config('services.telegram.dev_user_id'),
                'first_name' => 'Локальный',
                'last_name' => 'Пользователь',
            ];
            $telegramUser = TelegramUser::query()
                ->where('telegram_user_id', $telegramData['id'])
                ->first();
            $tree ??= $telegramUser?->currentTree;
        } elseif (! $familyUser && ! $request->session()->has('family_person_id')) {
            return response()->json([
                'message' => 'Войдите, чтобы открыть семейный архив.',
                'login_url' => route('login', $tree ? ['tree' => $tree->slug] : []),
                'password_login' => true,
            ], 401);
        }

        if (! $tree && $request->session()->has('family_person_id')) {
            $personTreeId = Person::withoutGlobalScope('family_tree')
                ->whereKey($request->session()->get('family_person_id'))
                ->value('tree_id');
            $tree = $personTreeId
                ? FamilyTree::query()->whereKey($personTreeId)->where('status', 'active')->first()
                : null;
        }

        if ($telegramData) {
            $isAdmin = in_array(
                (string) $telegramData['id'],
                config('services.telegram.admin_ids', []),
                true,
            );
            $telegramUser = TelegramUser::query()->updateOrCreate(
                ['telegram_user_id' => $telegramData['id']],
                [
                    'username' => $telegramData['username'] ?? null,
                    'first_name' => $telegramData['first_name'] ?? null,
                    'last_name' => $telegramData['last_name'] ?? null,
                    'language_code' => $telegramData['language_code'] ?? null,
                    'photo_url' => $telegramData['photo_url'] ?? null,
                    'is_bot_admin' => $isAdmin,
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
                $telegramUser->updateQuietly(['user_id' => $familyUser->id]);
            }
        }

        $tree ??= $familyUser ? $this->currentTree->resolveDefault($familyUser) : null;

        if ($request->session()->has('family_invitation_token') && $familyUser) {
            try {
                $membership = $this->treeAccess->acceptInvitation(
                    $familyUser,
                    (string) $request->session()->pull('family_invitation_token'),
                );
                $tree = $membership->tree;
            } catch (Throwable) {
                $membership = null;
            }
        } else {
            $membership = null;
        }

        if (! $tree) {
            return response()->json([
                'message' => 'Выберите семейное дерево или откройте действующее приглашение.',
                'trees' => $familyUser
                    ? $familyUser->memberships()
                        ->with('tree:id,name,slug')
                        ->where('status', 'approved')
                        ->get()
                        ->map(fn (TreeMembership $item): array => [
                            'name' => $item->tree?->name,
                            'slug' => $item->tree?->slug,
                        ])
                        ->filter(fn (array $item): bool => (bool) $item['slug'])
                        ->values()
                    : [],
                'selection_url' => $familyUser ? route('trees.choose') : route('login'),
            ], 409);
        }

        $this->currentTree->set($tree);
        $request->attributes->set('familyTree', $tree);
        $request->session()->put('family_tree_id', $tree->id);

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

        if (! $familyUser) {
            return response()->json([
                'message' => 'Сессия входа устарела. Войдите ещё раз.',
                'login_url' => route('login', ['tree' => $tree->slug]),
                'password_login' => true,
            ], 401);
        }

        $membership ??= $familyUser->is_super_admin
            ? new TreeMembership([
                'tree_id' => $tree->id,
                'user_id' => $familyUser->id,
                'role' => 'owner',
                'status' => 'approved',
            ])
            : $this->treeAccess->membership($familyUser, $tree);
        $membership->setRelation('tree', $tree);

        if (
            $membership->exists
            && $membership->status === 'pending'
            && $telegramUser?->isApproved()
            && (int) $telegramUser->current_tree_id === (int) $tree->id
        ) {
            $membership->update([
                'person_id' => $telegramUser->person_id,
                'role' => $telegramUser->person_id ? 'member' : 'guest',
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

        if ($telegramUser) {
            $telegramUser->updateQuietly(['current_tree_id' => $tree->id]);
            $request->attributes->set('telegramUser', $telegramUser);
            $request->session()->put('family_telegram_user_id', $telegramUser->id);
        }

        $familyUser->updateQuietly(['last_tree_id' => $tree->id]);
        $request->session()->put('family_user_id', $familyUser->id);
        $request->attributes->set('familyUser', $familyUser);
        $request->attributes->set('treeMembership', $membership);

        return $next($request);
    }

    private function requestedTree(Request $request): ?FamilyTree
    {
        if ($tree = $request->attributes->get('familyTree')) {
            return $tree;
        }

        $treeId = (int) $request->header('X-Family-Tree-ID');
        if ($treeId > 0) {
            return FamilyTree::query()->whereKey($treeId)->where('status', 'active')->first();
        }

        $slug = (string) ($request->header('X-Family-Tree') ?: $request->query('tree', ''));
        if ($slug !== '') {
            return FamilyTree::query()->where('slug', $slug)->where('status', 'active')->first();
        }

        return $request->session()->has('family_tree_id')
            ? FamilyTree::query()
                ->whereKey($request->session()->get('family_tree_id'))
                ->where('status', 'active')
                ->first()
            : null;
    }
}
