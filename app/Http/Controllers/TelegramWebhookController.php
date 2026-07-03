<?php

namespace App\Http\Controllers;

use App\Models\FamilyEvent;
use App\Models\FamilyTree;
use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\Setting;
use App\Models\TelegramGroup;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Services\ExternalIdentityService;
use App\Services\TelegramBot;
use App\Services\TelegramWebLogin;
use App\Services\TreeAccessService;
use App\Services\UserCredentialService;
use App\Support\CurrentTree;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly ExternalIdentityService $identities,
        private readonly TreeAccessService $treeAccess,
        private readonly CurrentTree $currentTree,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $routeTree = $request->route('tree');
        $routeTree = $routeTree instanceof FamilyTree ? $routeTree : null;
        if ($routeTree) {
            $this->currentTree->set($routeTree);
        }
        $secret = (string) ($routeTree?->custom_bot_webhook_secret ?: config('services.telegram.webhook_secret'));
        $receivedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($secret !== '' && ! hash_equals($secret, $receivedSecret)) {
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->validate([
            'update_id' => ['required', 'integer'],
        ]) + $request->all();

        $message = $payload['message'] ?? $payload['edited_message'] ?? null;
        $callback = $payload['callback_query'] ?? null;
        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;

        $update = TelegramUpdate::query()->firstOrNew([
            'telegram_update_id' => $payload['update_id'],
        ]);

        if ($update->exists && $update->processed_at) {
            return response()->json(['ok' => true]);
        }

        $update->fill([
            'chat_id' => $chat['id'] ?? null,
            'telegram_user_id' => $from['id'] ?? null,
            'update_type' => array_key_first(array_diff_key($payload, ['update_id' => true])),
            'payload' => $payload,
            'error' => null,
        ])->save();

        try {
            if ($message && $chat && $from) {
                $this->handleMessage($message, $chat, $from, $routeTree);
            }

            if ($callback) {
                $this->handleAccessCallback($callback);
            }

            $update->update(['processed_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
            $update->update(['error' => mb_substr($exception->getMessage(), 0, 4000)]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(
        array $message,
        array $chat,
        array $from,
        ?FamilyTree $routeTree = null,
    ): void {
        $isAdmin = in_array((string) $from['id'], config('services.telegram.admin_ids', []), true);

        $user = TelegramUser::query()->firstOrNew(['telegram_user_id' => $from['id']]);
        $user->fill([
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'last_name' => $from['last_name'] ?? null,
            'language_code' => $from['language_code'] ?? null,
            'is_bot_admin' => $isAdmin,
            'last_seen_at' => now(),
        ]);

        if (! $user->exists && (config('services.telegram.auto_approve') || $isAdmin)) {
            $user->status = 'approved';
        }

        $user->save();

        $group = null;
        $tree = $routeTree ?: $user->currentTree;
        $isGroupChat = in_array($chat['type'] ?? '', ['group', 'supergroup'], true);

        if ($isGroupChat && $tree) {
            $group = TelegramGroup::query()->firstOrNew(['telegram_chat_id' => $chat['id']]);
            $tree = $group->tree ?: $tree;
            $group->fill([
                'title' => $chat['title'] ?? 'Семейная группа',
                'last_seen_at' => now(),
            ]);
            if ($tree) {
                $group->tree_id = $tree->id;
            }
            $group->save();
        }

        $familyUser = $user->user ?: $this->identities->resolve('telegram', $user->telegram_user_id, [
            'username' => $user->username,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
        ]);
        $user->updateQuietly(['user_id' => $familyUser->id]);

        $text = trim((string) ($message['text'] ?? ''));
        [$rawCommand, $arguments] = array_pad(preg_split('/\s+/u', $text, 2), 2, '');
        $command = mb_strtolower($rawCommand);
        $command = preg_replace('/@[^ ]+$/', '', $command);

        if (! $tree) {
            if ($command === '/trees') {
                $this->sendTreeSelector($chat['id'], $user);
            } elseif ($command === '/start') {
                $this->bot->sendMessage(
                    $chat['id'],
                    'Добро пожаловать в «Я и дом мой». Откройте семейное приглашение или выберите уже доступное дерево.',
                );
                $this->sendTreeSelector($chat['id'], $user);
            } else {
                $this->bot->sendMessage(
                    $chat['id'],
                    $isGroupChat
                        ? 'Эта группа ещё не назначена семейному дереву.'
                        : 'У вас не выбрано семейное дерево. Откройте приглашение или используйте /trees.',
                );
            }

            return;
        }

        $this->currentTree->set($tree);
        $user->updateQuietly(['current_tree_id' => $tree->id]);
        $membership = $this->treeAccess->membership($familyUser, $tree);
        if ($membership->wasRecentlyCreated) {
            $accessStatus = config('services.telegram.auto_approve') || $isAdmin
                ? 'approved'
                : 'pending';
            $membership->update([
                'person_id' => $user->person_id,
                'role' => $isAdmin ? 'moderator' : ($user->person_id ? 'member' : 'guest'),
                'status' => $accessStatus,
                'approved_at' => $accessStatus === 'approved' ? now() : null,
            ]);
        }

        if ($command === '/start' && $arguments === 'credentials') {
            $this->sendWebCredentials($chat['id'], $user);

            return;
        }

        if ($command === '/start' && $arguments === 'site') {
            $this->sendWebsiteLogin($chat['id'], $user);

            return;
        }

        if ($command === '/start') {
            $this->sendWelcome($chat['id'], $user);

            return;
        }

        if ($group && ! $group->is_active) {
            if (str_starts_with($command, '/')) {
                $this->bot->sendMessage(
                    $chat['id'],
                    'Эта группа зарегистрирована, но ещё не подтверждена администратором семьи.',
                );
            }

            return;
        }

        if ($command === '/trees') {
            $this->sendTreeSelector($chat['id'], $user);

            return;
        }

        if ($membership->status !== 'approved' && ! $isAdmin) {
            if (str_starts_with($command, '/')) {
                $this->bot->sendMessage(
                    $chat['id'],
                    'Ваш доступ ожидает подтверждения администратора семьи.',
                );
            }

            return;
        }

        if (! str_starts_with($command, '/') && $user->pending_command) {
            $pendingCommand = $user->pending_command;
            $user->update(['pending_command' => null]);
            $this->sendPersonSearch($chat['id'], $user, $text, $pendingCommand);

            return;
        }

        if (str_starts_with($command, '/') && ! in_array($command, ['/person', '/family'], true)) {
            $user->update(['pending_command' => null]);
        }

        $this->queueMiniAppActionForCommand($user, $command);

        match ($command) {
            '/tree' => $this->sendTreeButton($chat['id']),
            '/list' => $this->sendSectionButton($chat['id'], 'Семейный список', 'list'),
            '/photos' => $this->sendSectionButton($chat['id'], 'Семейные фотографии', 'gallery'),
            '/birthdays' => $this->sendBirthdays($chat['id']),
            '/person' => $this->askForPersonName($chat['id'], $user, 'person'),
            '/family' => $this->askForPersonName($chat['id'], $user, 'family'),
            '/me' => $this->sendMyFamily($chat['id'], $user),
            '/grandchildren' => $this->sendRelativeList($chat['id'], $user, 'grandchildren', 'Мои внуки'),
            '/nephews' => $this->sendRelativeList($chat['id'], $user, 'nephews', 'Мои племянники'),
            '/events' => $this->sendEvents($chat['id']),
            '/stats' => $this->sendStats($chat['id']),
            '/trees' => $this->sendTreeSelector($chat['id'], $user),
            '/credentials' => $this->sendWebCredentials($chat['id'], $user),
            '/site' => $this->sendWebsiteLogin($chat['id'], $user),
            '/help' => $this->sendHelp($chat['id']),
            default => null,
        };
    }

    private function handleAccessCallback(array $callback): void
    {
        $from = $callback['from'] ?? [];
        $data = (string) ($callback['data'] ?? '');
        $isConfiguredAdmin = in_array(
            (string) ($from['id'] ?? ''),
            config('services.telegram.admin_ids', []),
            true,
        );
        $actor = TelegramUser::query()
            ->where('telegram_user_id', $from['id'] ?? 0)
            ->first();
        if ($actor?->currentTree) {
            $this->currentTree->set($actor->currentTree);
        }

        if (preg_match('/^tree:select:(\d+)$/', $data, $treeMatch)) {
            $membership = $actor?->user?->memberships()
                ->with(['tree', 'person'])
                ->where('tree_id', $treeMatch[1])
                ->where('status', 'approved')
                ->first();

            if (! $membership) {
                $this->bot->request('answerCallbackQuery', [
                    'callback_query_id' => $callback['id'],
                    'text' => 'Нет доступа к этому дереву.',
                    'show_alert' => true,
                ]);

                return;
            }

            $actor->updateQuietly([
                'current_tree_id' => $membership->tree_id,
                'person_id' => $membership->person_id,
                'status' => $membership->status,
            ]);
            $this->currentTree->set($membership->tree);
            $this->queueMiniAppAction($actor, [
                'tab' => 'tree',
                'focus' => $membership->person_id,
                'tree_id' => $membership->tree_id,
                'tree_name' => $membership->tree->name,
            ]);
            $this->bot->request('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => 'Выбрано дерево: '.$membership->tree->name,
            ]);

            return;
        }

        if (preg_match('/^membership:(approve|block|moderator|member):(\d+)$/', $data, $membershipMatch)) {
            $this->handleMembershipCallback(
                $callback,
                $actor,
                $isConfiguredAdmin,
                $membershipMatch[1],
                (int) $membershipMatch[2],
            );

            return;
        }

        if (! $isConfiguredAdmin && ! $actor?->user?->is_super_admin) {
            $this->answerAccessCallback($callback, 'Недостаточно прав.', true);

            return;
        }

        if (! preg_match('/^access:(approve|block|admin|unadmin):(\d+)$/', $data, $matches)) {
            $this->answerAccessCallback($callback, 'Команда больше не поддерживается.');

            return;
        }

        $user = TelegramUser::query()->find($matches[2]);

        if (! $user) {
            $this->answerAccessCallback($callback, 'Пользователь уже удалён.');

            return;
        }

        match ($matches[1]) {
            'approve' => $user->update(['status' => 'approved']),
            'block' => $user->update(['status' => 'blocked']),
            'admin' => $user->update(['status' => 'approved', 'is_bot_admin' => true]),
            'unadmin' => $user->update(['is_bot_admin' => false]),
        };
        $targetMembership = $user->user && $user->currentTree
            ? $this->treeAccess->membership($user->user, $user->currentTree)
            : null;
        if ($targetMembership) {
            $targetMembership->update([
                'status' => $user->status,
                'role' => match ($matches[1]) {
                    'admin' => 'moderator',
                    'unadmin' => $targetMembership->role === 'moderator' ? 'member' : $targetMembership->role,
                    default => $targetMembership->role,
                },
                'approved_at' => $user->status === 'approved'
                    ? ($targetMembership->approved_at ?: now())
                    : null,
            ]);
        }

        $actionText = match ($matches[1]) {
            'approve' => '✅ Доступ разрешён',
            'block' => '⛔ Доступ заблокирован',
            'admin' => '👑 Назначен администратором',
            'unadmin' => '👤 Права администратора сняты',
        };

        $this->answerAccessCallback($callback, $actionText);

        if (isset($callback['message']['chat']['id'], $callback['message']['message_id'])) {
            try {
                $this->bot->request('editMessageText', [
                    'chat_id' => $callback['message']['chat']['id'],
                    'message_id' => $callback['message']['message_id'],
                    'text' => '👤 <b>'.e($user->display_name)."</b>\n\n{$actionText}",
                    'parse_mode' => 'HTML',
                    'reply_markup' => $this->accessKeyboard($user),
                ]);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function handleMembershipCallback(
        array $callback,
        ?TelegramUser $actor,
        bool $isConfiguredAdmin,
        string $action,
        int $membershipId,
    ): void {
        $membership = TreeMembership::query()
            ->with(['tree', 'user', 'person'])
            ->find($membershipId);

        if (! $membership) {
            $this->answerAccessCallback($callback, 'Заявка уже удалена.', true);

            return;
        }

        $actorUser = $actor?->user;
        $actorRole = $actorUser?->memberships()
            ->where('tree_id', $membership->tree_id)
            ->where('status', 'approved')
            ->value('role');
        $canModerate = $isConfiguredAdmin
            || (bool) $actorUser?->is_super_admin
            || in_array($actorRole, ['owner', 'moderator'], true);
        $canAssignModerator = $isConfiguredAdmin
            || (bool) $actorUser?->is_super_admin
            || $actorRole === 'owner';

        if (! $canModerate || ($action === 'moderator' && ! $canAssignModerator)) {
            $this->answerAccessCallback($callback, 'Недостаточно прав для этого дерева.', true);

            return;
        }

        if ($membership->role === 'owner') {
            $this->answerAccessCallback($callback, 'Статус владельца меняется только в панели платформы.', true);

            return;
        }

        $changes = match ($action) {
            'approve' => [
                'status' => 'approved',
                'approved_by_user_id' => $actorUser?->id,
                'approved_at' => now(),
            ],
            'block' => [
                'status' => 'blocked',
                'approved_by_user_id' => $actorUser?->id,
                'approved_at' => null,
            ],
            'moderator' => [
                'status' => 'approved',
                'role' => 'moderator',
                'approved_by_user_id' => $actorUser?->id,
                'approved_at' => $membership->approved_at ?: now(),
            ],
            default => [
                'role' => 'member',
            ],
        };
        $membership->update($changes);
        TelegramUser::query()
            ->where('user_id', $membership->user_id)
            ->update([
                'current_tree_id' => $membership->tree_id,
                'person_id' => $membership->person_id,
                'status' => $membership->status,
            ]);
        $this->currentTree->set($membership->tree);

        $actionText = match ($action) {
            'approve' => '✅ Доступ разрешён',
            'block' => '⛔ Доступ заблокирован',
            'moderator' => '🛡 Назначен модератором',
            default => '👤 Назначен членом семьи',
        };
        $this->answerAccessCallback($callback, $actionText);

        if (isset($callback['message']['chat']['id'], $callback['message']['message_id'])) {
            $this->bot->request('editMessageText', [
                'chat_id' => $callback['message']['chat']['id'],
                'message_id' => $callback['message']['message_id'],
                'text' => '👤 <b>'.e($membership->user->name)."</b>\n"
                    .'🌳 '.e($membership->tree->name)."\n\n{$actionText}",
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [[
                            'text' => $membership->status === 'approved'
                                ? '⛔ Отключить доступ'
                                : '✅ Разрешить доступ',
                            'callback_data' => 'membership:'
                                .($membership->status === 'approved' ? 'block' : 'approve')
                                .':'.$membership->id,
                        ]],
                        [[
                            'text' => $membership->role === 'moderator'
                                ? '👤 Сделать членом семьи'
                                : '🛡 Сделать модератором',
                            'callback_data' => 'membership:'
                                .($membership->role === 'moderator' ? 'member' : 'moderator')
                                .':'.$membership->id,
                        ]],
                    ],
                ],
            ]);
        }
    }

    private function answerAccessCallback(array $callback, string $text, bool $showAlert = false): void
    {
        if (! isset($callback['id'])) {
            return;
        }

        try {
            $this->bot->request('answerCallbackQuery', [
                'callback_query_id' => $callback['id'],
                'text' => $text,
                'show_alert' => $showAlert,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function accessKeyboard(TelegramUser $user): array
    {
        return [
            'inline_keyboard' => [
                [[
                    'text' => $user->isApproved() ? '⛔ Отключить доступ' : '✅ Разрешить доступ',
                    'callback_data' => 'access:'.($user->isApproved() ? 'block' : 'approve').':'.$user->id,
                ]],
                [[
                    'text' => $user->is_bot_admin ? '👤 Снять права админа' : '👑 Сделать админом',
                    'callback_data' => 'access:'.($user->is_bot_admin ? 'unadmin' : 'admin').':'.$user->id,
                ]],
            ],
        ];
    }

    private function sendWelcome(int $chatId, TelegramUser $user): void
    {
        if (! $user->isApproved() && ! $user->is_bot_admin) {
            $this->bot->sendMessage(
                $chatId,
                'Привет! Заявка на доступ создана. Администратор семьи подтвердит её в админке.',
            );

            return;
        }

        $this->bot->sendMessage(
            $chatId,
            e(Setting::value('welcome_text', 'Добро пожаловать в «Я и дом мой»!'))
                ."\n\nЗдесь можно открыть семейное древо и посмотреть ближайшие дни рождения.",
            $this->treeKeyboard($chatId),
        );
    }

    private function sendTreeButton(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            'Откройте семейное древо:',
            $this->treeKeyboard($chatId),
        );
    }

    private function sendBirthdays(int $chatId): void
    {
        $today = now()->startOfDay();
        $birthdays = Person::query()
            ->where('is_published', true)
            ->whereNull('death_date')
            ->whereNotNull('birth_date')
            ->get()
            ->map(function (Person $person) use ($today): array {
                $date = Carbon::create(
                    $today->year,
                    $person->birth_date->month,
                    min($person->birth_date->day, Carbon::create($today->year, $person->birth_date->month)->daysInMonth),
                );

                if ($date->lt($today)) {
                    $date->addYear();
                }

                return ['person' => $person, 'date' => $date, 'days' => $today->diffInDays($date)];
            })
            ->sortBy('days')
            ->take(10);

        if ($birthdays->isEmpty()) {
            $this->bot->sendMessage($chatId, 'Дни рождения пока не добавлены.');

            return;
        }

        $lines = $birthdays->map(function (array $item): string {
            $person = $item['person'];
            $date = $item['date'];
            $name = e($person->full_name);
            $when = $item['days'] === 0 ? 'сегодня' : $date->translatedFormat('j F');

            $birthDate = $person->birth_date->format('d.m.Y');
            $age = $person->birth_date->diffInYears($date);

            return "🎂 <b>{$name}</b>\n"
                ."   📅 {$birthDate} · исполнится {$age}\n"
                ."   ⏳ {$when}";
        })->implode("\n");

        $this->bot->sendMessage($chatId, "<b>Ближайшие дни рождения</b>\n\n{$lines}");
    }

    private function sendHelp(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "<b>Команды</b>\n"
            ."/tree — открыть древо\n"
            ."/list — удобный список родственников\n"
            ."/photos — семейная фотогалерея\n"
            ."/person — найти человека (имя напишите следующим сообщением)\n"
            ."/family — открыть семейную ветвь найденного человека\n"
            ."/me — моя карточка и близкие\n"
            ."/grandchildren — мои внуки\n"
            ."/nephews — мои племянники\n"
            ."/birthdays — ближайшие дни рождения\n"
            ."/events — семейные события\n"
            ."/stats — статистика архива\n"
            ."/trees — выбрать семейное дерево\n"
            ."/credentials — получить логин и новый пароль для сайта\n"
            ."/site — войти на сайт без пароля\n"
            .'/help — эта подсказка',
        );
    }

    private function sendTreeSelector(int $chatId, TelegramUser $user): void
    {
        $memberships = $user->user?->memberships()
            ->with('tree')
            ->where('status', 'approved')
            ->get();

        if (! $memberships || $memberships->isEmpty()) {
            $this->bot->sendMessage($chatId, 'У вас пока нет подтверждённого доступа к семейным деревьям.');

            return;
        }

        $keyboard = $memberships->map(fn ($membership): array => [[
            'text' => ($membership->tree_id === $user->current_tree_id ? '✓ ' : '🌿 ')
                .$membership->tree->name,
            'callback_data' => 'tree:select:'.$membership->tree_id,
        ]])->all();
        $this->bot->sendMessage($chatId, 'Выберите семейное дерево:', [
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);
    }

    private function sendWebCredentials(int $chatId, TelegramUser $user): void
    {
        $membership = $user->user?->memberships()
            ->where('tree_id', $user->current_tree_id)
            ->where('status', 'approved')
            ->first();

        if (! $membership) {
            $this->bot->sendMessage(
                $chatId,
                'Сначала администратор семьи должен разрешить вам доступ.',
            );

            return;
        }

        if ($chatId !== (int) $user->telegram_user_id) {
            $this->sendPrivateBotButton(
                $chatId,
                'Логин и пароль нельзя публиковать в группе. Откройте личный чат с ботом:',
                '🔐 Получить логин и пароль',
                'credentials',
            );

            return;
        }

        $person = $membership->person;

        if (! $person) {
            $this->bot->sendMessage(
                $chatId,
                'Ваш Telegram ещё не привязан к человеку в древе. Попросите администратора выполнить привязку.',
            );

            return;
        }

        app(UserCredentialService::class)->issueAndSend($user->user, $membership->tree);
    }

    private function sendWebsiteLogin(int $chatId, TelegramUser $user): void
    {
        $hasAccess = $user->user?->memberships()
            ->where('tree_id', $user->current_tree_id)
            ->where('status', 'approved')
            ->exists();

        if (! $hasAccess) {
            $this->bot->sendMessage(
                $chatId,
                'Сначала администратор семьи должен разрешить вам доступ.',
            );

            return;
        }

        if ($chatId !== (int) $user->telegram_user_id) {
            $this->sendPrivateBotButton(
                $chatId,
                'Для безопасного входа откройте личный чат с ботом:',
                '🌐 Перейти на сайт',
                'site',
            );

            return;
        }

        $url = app(TelegramWebLogin::class)->createUrl($user);

        $this->bot->sendMessage(
            $user->telegram_user_id,
            "🌐 <b>Вход на семейный сайт</b>\n\n"
            .'Кнопка автоматически авторизует вас. Ссылка действует 15 минут и только один раз.',
            [
                'protect_content' => true,
                'reply_markup' => [
                    'inline_keyboard' => [[
                        ['text' => '🌿 Открыть семейный сайт', 'url' => $url],
                    ]],
                ],
            ],
        );
    }

    private function sendPrivateBotButton(
        int $chatId,
        string $message,
        string $buttonText,
        string $startParameter,
    ): void {
        $username = ltrim((string) config('services.telegram.bot_username'), '@');

        $this->bot->sendMessage($chatId, $message, [
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => $buttonText,
                        'url' => "https://t.me/{$username}?start={$startParameter}",
                    ],
                ]],
            ],
        ]);
    }

    private function askForPersonName(int $chatId, TelegramUser $user, string $mode): void
    {
        $user->update(['pending_command' => $mode]);
        $label = $mode === 'family' ? 'семейную ветвь' : 'человека';

        $this->bot->sendMessage(
            $chatId,
            "🔎 Напишите следующим сообщением имя или фамилию.\n\nЯ найду {$label}.",
        );
    }

    private function sendSectionButton(int $chatId, string $title, string $tab): void
    {
        $this->bot->sendMessage($chatId, 'Открыть раздел «'.e($title).'»:',
            ['reply_markup' => ['inline_keyboard' => [[
                $this->miniAppButton(
                    $chatId,
                    '🌿 '.$title,
                    $this->treeBaseUrl().'?'.http_build_query(['tab' => $tab]),
                ),
            ]]]],
        );
    }

    private function sendRelativeList(
        int $chatId,
        TelegramUser $user,
        string $relation,
        string $title,
    ): void {
        if (! $user->person_id) {
            $this->bot->sendMessage($chatId, 'Сначала администратор должен привязать вас к человеку в древе.');

            return;
        }

        $url = $this->familyUrl($user->person_id).'?'.http_build_query([
            'tab' => 'list',
            'relation' => $relation,
        ]);
        $this->bot->sendMessage($chatId, 'Открыть раздел «'.e($title).'»:',
            ['reply_markup' => ['inline_keyboard' => [[
                $this->miniAppButton($chatId, '🌿 '.$title, $url),
            ]]]],
        );
    }

    private function sendPersonSearch(
        int $chatId,
        TelegramUser $user,
        string $query,
        string $mode = 'person',
    ): void {
        $query = trim($query);

        if ($query === '') {
            $this->bot->sendMessage($chatId, 'Напишите имя или фамилию следующим сообщением.');

            return;
        }

        $term = '%'.$query.'%';
        $people = Person::query()
            ->where('is_published', true)
            ->where(function ($builder) use ($term): void {
                $builder
                    ->where('first_name', 'like', $term)
                    ->orWhere('middle_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('maiden_name', 'like', $term)
                    ->orWhere('married_name', 'like', $term);
            })
            ->limit(6)
            ->get();

        if ($people->isEmpty()) {
            $this->bot->sendMessage($chatId, 'Никого не нашёл. Попробуйте фамилию или часть имени.');

            return;
        }

        $this->queueMiniAppAction($user, [
            'tab' => 'list',
            'q' => $query,
            'scope' => 'all',
        ]);

        $keyboard = $people->map(fn (Person $person): array => [
            $this->miniAppButton(
                $chatId,
                '🌿 '.$person->full_name,
                $this->familyUrl($person->id),
            ),
        ])->values()->all();

        $title = $mode === 'family' ? 'Выберите семейную ветвь:' : 'Выберите человека:';
        $this->bot->sendMessage(
            $chatId,
            "🔎 <b>{$title}</b>\nНайдено: ".$people->count(),
            ['reply_markup' => ['inline_keyboard' => $keyboard]],
        );
    }

    private function queueMiniAppActionForCommand(TelegramUser $user, string $command): void
    {
        $action = match ($command) {
            '/tree' => ['tab' => 'tree', 'focus' => $user->person_id, 'scope' => 'branch'],
            '/list' => ['tab' => 'list', 'focus' => $user->person_id, 'scope' => 'branch'],
            '/photos' => ['tab' => 'gallery'],
            '/birthdays' => ['tab' => 'birthdays'],
            '/events' => ['tab' => 'events'],
            '/me' => ['tab' => 'me'],
            '/grandchildren' => $user->person_id ? [
                'tab' => 'list',
                'focus' => $user->person_id,
                'relation' => 'grandchildren',
                'scope' => 'branch',
            ] : null,
            '/nephews' => $user->person_id ? [
                'tab' => 'list',
                'focus' => $user->person_id,
                'relation' => 'nephews',
                'scope' => 'branch',
            ] : null,
            default => null,
        };

        if ($action) {
            $this->queueMiniAppAction($user, $action);
        }
    }

    private function queueMiniAppAction(TelegramUser $user, array $action): void
    {
        $action['tree_id'] ??= $this->currentTree->id();
        $action['tree_name'] ??= $this->currentTree->get()?->name;
        $user->updateQuietly([
            'mini_app_action' => array_filter(
                $action,
                fn (mixed $value): bool => $value !== null && $value !== '',
            ),
        ]);
    }

    private function sendMyFamily(int $chatId, TelegramUser $user): void
    {
        $person = $user->person;

        if (! $person) {
            $this->bot->sendMessage(
                $chatId,
                'Ваша учётная запись ещё не привязана к человеку в древе. Это можно сделать в админке.',
            );

            return;
        }

        $parents = $person->parents()->get()->pluck('full_name')->implode(', ') ?: 'не указаны';
        $children = $person->children()->get()->pluck('full_name')->implode(', ') ?: 'не указаны';
        $siblings = Person::query()
            ->whereHas('parents', fn ($query) => $query->whereIn('people.id', $person->parents()->pluck('people.id')))
            ->whereKeyNot($person->id)
            ->get()
            ->pluck('full_name')
            ->implode(', ') ?: 'не указаны';
        $partners = Partnership::query()
            ->with(['partnerOne', 'partnerTwo'])
            ->where('partner_one_id', $person->id)
            ->orWhere('partner_two_id', $person->id)
            ->get()
            ->map(fn (Partnership $partnership): string => $partnership->partner_one_id === $person->id
                ? $partnership->partnerTwo->full_name
                : $partnership->partnerOne->full_name)
            ->implode(', ') ?: 'не указаны';

        $birth = $person->birth_date
            ? $person->birth_date->translatedFormat('d.m.Y')
                .($person->death_date ? '' : ' · '.$person->age.' лет')
            : 'не указана';
        $deathLine = $person->death_date
            ? "\n🕯 <b>Дата смерти:</b> ".$person->death_date->translatedFormat('d.m.Y')
            : '';

        $this->bot->sendMessage(
            $chatId,
            "🌿 <b>МОЯ КАРТОЧКА</b>\n\n"
            .'👤 <b>'.e($person->full_name)."</b>\n"
            .'🎂 <b>Дата рождения:</b> '.e($birth)
            .$deathLine."\n"
            .'📍 <b>Место рождения:</b> '.e($person->birth_place ?: 'не указано')."\n"
            .'🏠 <b>Город:</b> '.e($person->current_city ?: 'не указан')."\n"
            .'💼 <b>Род занятий:</b> '.e($person->occupation ?: 'не указан')."\n\n"
            ."👪 <b>СЕМЬЯ</b>\n"
            .'⬆️ <b>Родители:</b> '.e($parents)."\n"
            .'💍 <b>Супруги / партнёры:</b> '.e($partners)."\n"
            .'↔️ <b>Братья и сёстры:</b> '.e($siblings)."\n"
            .'⬇️ <b>Дети:</b> '.e($children),
            $this->treeKeyboard($chatId, $person->id),
        );
    }

    private function sendEvents(int $chatId): void
    {
        $today = now()->startOfDay();
        $events = FamilyEvent::query()
            ->where('is_published', true)
            ->get()
            ->map(function (FamilyEvent $event) use ($today): array {
                $date = $event->event_date->copy();

                if ($event->is_annual) {
                    $date->year($today->year);
                    if ($date->lt($today)) {
                        $date->addYear();
                    }
                }

                return ['event' => $event, 'date' => $date];
            })
            ->filter(fn (array $item): bool => $item['date']->gte($today))
            ->sortBy('date')
            ->take(10);

        if ($events->isEmpty()) {
            $this->bot->sendMessage($chatId, 'Ближайших семейных событий пока нет.');

            return;
        }

        $lines = $events->map(fn (array $item): string => '📅 <b>'
            .e($item['event']->title).'</b> — '
            .$item['date']->translatedFormat('j F Y'))->implode("\n");
        $this->bot->sendMessage($chatId, "<b>Ближайшие события</b>\n\n{$lines}");
    }

    private function sendStats(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "<b>Я и дом мой</b>\n"
            .'Людей: '.Person::query()->where('is_published', true)->count()."\n"
            .'Семейных союзов: '.Partnership::query()->count()."\n"
            .'Связей родитель — ребёнок: '.ParentChild::query()->count()."\n"
            .'Городов проживания: '.Person::query()->whereNotNull('current_city')->distinct()->count('current_city'),
        );
    }

    private function treeKeyboard(int $chatId, ?int $focusId = null): array
    {
        return [
            'reply_markup' => [
                'inline_keyboard' => [[
                    $this->miniAppButton(
                        $chatId,
                        '🌳 Открыть семейное древо',
                        $focusId
                            ? $this->familyUrl($focusId)
                            : $this->treeBaseUrl(),
                    ),
                ]],
            ],
        ];
    }

    private function miniAppButton(int $chatId, string $text, string $url): array
    {
        $button = ['text' => $text];

        if ($chatId > 0) {
            $button['web_app'] = ['url' => $url];
        } else {
            $username = ltrim((string) config('services.telegram.bot_username'), '@');
            $startParameter = 'tree_'.($this->currentTree->id() ?: 0).'_'.$this->miniAppStartParameter($url);
            $button['url'] = "https://t.me/{$username}?startapp=".rawurlencode($startParameter);
        }

        return $button;
    }

    private function miniAppStartParameter(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $focusId = null;

        if (preg_match('~/family/(?:[^/]+/)?person/(\d+)~', $path, $matches)) {
            $focusId = (int) $matches[1];
        }

        $tab = in_array($query['tab'] ?? null, ['tree', 'list', 'gallery', 'birthdays', 'me'], true)
            ? $query['tab']
            : 'tree';
        $relation = in_array($query['relation'] ?? null, ['grandchildren', 'nephews'], true)
            ? $query['relation']
            : null;

        if ($focusId && $tab === 'list' && $relation) {
            return "list_{$relation}_{$focusId}";
        }

        if ($focusId) {
            return "person_{$focusId}";
        }

        return "tab_{$tab}";
    }

    private function familyUrl(int $focusId): string
    {
        $tree = $this->currentTree->get();

        return $tree
            ? route('family.tree.person', ['tree' => $tree, 'person' => $focusId])
            : route('family.person', ['person' => $focusId]);
    }

    private function treeBaseUrl(): string
    {
        return $this->currentTree->get()
            ? route('family.tree', $this->currentTree->get())
            : config('services.telegram.mini_app_url');
    }
}
