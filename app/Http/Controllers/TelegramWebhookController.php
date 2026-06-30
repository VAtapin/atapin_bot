<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Setting;
use App\Models\TelegramGroup;
use App\Models\TelegramUpdate;
use App\Models\TelegramUser;
use App\Services\TelegramBot;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        $receivedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        if ($secret !== '' && ! hash_equals($secret, $receivedSecret)) {
            return response()->json(['ok' => false], 403);
        }

        $payload = $request->validate([
            'update_id' => ['required', 'integer'],
        ]) + $request->all();

        $message = $payload['message'] ?? $payload['edited_message'] ?? null;
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
                $this->handleMessage($message, $chat, $from);
            }

            $update->update(['processed_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
            $update->update(['error' => mb_substr($exception->getMessage(), 0, 4000)]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => true]);
    }

    private function handleMessage(array $message, array $chat, array $from): void
    {
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

        if (in_array($chat['type'] ?? '', ['group', 'supergroup'], true)) {
            $group = TelegramGroup::query()->updateOrCreate(
                ['telegram_chat_id' => $chat['id']],
                [
                    'title' => $chat['title'] ?? 'Семейная группа',
                    'last_seen_at' => now(),
                ],
            );
        }

        $text = trim((string) ($message['text'] ?? ''));
        $command = mb_strtolower(strtok($text, ' ') ?: '');
        $command = preg_replace('/@[^ ]+$/', '', $command);

        if ($command === '/start') {
            $this->sendWelcome($chat['id'], $user);

            return;
        }

        if ($group && ! $group->is_active) {
            if (in_array($command, ['/tree', '/birthdays', '/help'], true)) {
                $this->bot->sendMessage(
                    $chat['id'],
                    'Эта группа зарегистрирована, но ещё не подтверждена администратором семьи.',
                );
            }

            return;
        }

        if (! $user->isApproved() && ! $isAdmin) {
            if (str_starts_with($command, '/')) {
                $this->bot->sendMessage(
                    $chat['id'],
                    'Ваш доступ ожидает подтверждения администратора семьи.',
                );
            }

            return;
        }

        match ($command) {
            '/tree' => $this->sendTreeButton($chat['id']),
            '/birthdays' => $this->sendBirthdays($chat['id']),
            '/help' => $this->sendHelp($chat['id']),
            default => null,
        };
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
            e(Setting::value('welcome_text', 'Добро пожаловать в семейный архив!'))
                ."\n\nЗдесь можно открыть семейное древо и посмотреть ближайшие дни рождения.",
            $this->treeKeyboard(),
        );
    }

    private function sendTreeButton(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            'Откройте семейное древо:',
            $this->treeKeyboard(),
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

            return "🎂 <b>{$name}</b> — {$when}";
        })->implode("\n");

        $this->bot->sendMessage($chatId, "<b>Ближайшие дни рождения</b>\n\n{$lines}");
    }

    private function sendHelp(int $chatId): void
    {
        $this->bot->sendMessage(
            $chatId,
            "<b>Команды</b>\n/tree — семейное древо\n/birthdays — ближайшие дни рождения\n/help — эта подсказка",
        );
    }

    private function treeKeyboard(): array
    {
        return [
            'reply_markup' => [
                'inline_keyboard' => [[
                    [
                        'text' => '🌳 Открыть семейное древо',
                        'web_app' => ['url' => config('services.telegram.mini_app_url')],
                    ],
                ]],
            ],
        ];
    }
}
