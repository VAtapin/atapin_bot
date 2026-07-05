<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\DataIssue;
use App\Models\ExternalIdentity;
use App\Models\FamilyTree;
use App\Models\TreeMembership;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FamilyNotificationService
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function membershipRequested(TreeMembership $membership): void
    {
        $tree = $membership->tree;
        $text = "👋 <b>Новый участник дерева</b>\n\n"
            .'Дерево: <b>'.e($tree->name)."</b>\n"
            .'Пользователь: '.e($membership->user->name)."\n"
            .'Статус: ожидает подтверждения.';
        $this->sendToManagers($tree, $text, [
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '✅ Разрешить',
                            'callback_data' => 'membership:approve:'.$membership->id,
                        ],
                        [
                            'text' => '⛔ Заблокировать',
                            'callback_data' => 'membership:block:'.$membership->id,
                        ],
                    ],
                    [[
                        'text' => '🛡 Сделать модератором',
                        'callback_data' => 'membership:moderator:'.$membership->id,
                    ]],
                ],
            ],
        ]);
    }

    public function membershipChanged(TreeMembership $membership): void
    {
        $identity = ExternalIdentity::query()
            ->where('user_id', $membership->user_id)
            ->where('provider', 'telegram')
            ->first();
        if (! $identity) {
            return;
        }

        $text = "🔔 <b>Доступ к семейному дереву изменён</b>\n\n"
            .'Дерево: <b>'.e($membership->tree->name)."</b>\n"
            .'Роль: '.e(TreeMembership::ROLES[$membership->role] ?? $membership->role)."\n"
            .'Доступ: '.e(match ($membership->status) {
                'approved' => 'разрешён',
                'blocked' => 'заблокирован',
                default => 'ожидает подтверждения',
            })
            .($membership->person
                ? "\nКарточка: <b>".e($membership->person->full_name).'</b>'
                : "\nКарточка человека пока не привязана.");
        $this->safeSend($identity->provider_user_id, $text);
    }

    public function issueCreated(DataIssue $issue): void
    {
        $this->sendToManagers(
            $issue->tree,
            "⚠️ <b>Новое сообщение об ошибке</b>\n\n"
            .'<b>'.e($issue->subject)."</b>\n".e(mb_substr($issue->description, 0, 500)),
        );
    }

    public function treeChanged(ChangeLog $change): void
    {
        $key = "tree-change-notification:{$change->tree_id}";
        if (! Cache::add($key, true, now()->addMinutes(10))) {
            return;
        }

        $change->loadMissing(['tree', 'user']);
        $subject = $this->changeSubject($change);
        $fields = $this->changedFields($change);
        $this->sendToManagers(
            $change->tree,
            "✏️ <b>Изменение в семейном дереве</b>\n\n"
            .'Дерево: <b>'.e($change->tree->name)."</b>\n"
            .'Кто изменил: <b>'.e($change->user?->name ?: 'не определено')."</b>\n"
            .'Что: <b>'.e($subject)."</b>\n"
            .'Действие: '.e($this->changeAction($change->action))
            .($fields !== '' ? "\nИзменено: ".e($fields) : '')
            ."\n\nПолучатели: владелец и модераторы этого дерева."
            ."\nОткройте историю изменений в админке для подробной проверки.",
        );
    }

    public function notifyManagers(FamilyTree $tree, string $text): void
    {
        $this->sendToManagers($tree, $text);
    }

    private function sendToManagers(FamilyTree $tree, string $text, array $options = []): void
    {
        $userIds = $tree->memberships()
            ->where('status', 'approved')
            ->whereIn('role', ['owner', 'moderator'])
            ->pluck('user_id');
        ExternalIdentity::query()
            ->whereIn('user_id', $userIds)
            ->where('provider', 'telegram')
            ->pluck('provider_user_id')
            ->each(fn (string $telegramId) => $this->safeSend($telegramId, $text, $options));
    }

    private function safeSend(int|string $chatId, string $text, array $options = []): void
    {
        if (! config('services.telegram.bot_token')) {
            return;
        }

        try {
            $this->bot->sendMessage($chatId, $text, $options);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function changeSubject(ChangeLog $change): string
    {
        $data = array_replace($change->before ?? [], $change->after ?? []);
        $type = class_basename($change->subject_type);

        if ($type === 'Person') {
            $name = collect([
                $data['last_name'] ?? null,
                $data['first_name'] ?? null,
                $data['middle_name'] ?? null,
            ])->filter()->implode(' ');

            return 'карточка человека'.($name !== '' ? ' — '.$name : " #{$change->subject_id}");
        }

        $label = match ($type) {
            'ParentChild' => 'связь родитель — ребёнок',
            'Partnership' => 'супружеская связь',
            'PersonPhoto' => 'фотография',
            'PhotoAlbum' => 'фотоальбом',
            'FamilyEvent' => 'семейное событие',
            'TelegramGroup' => 'Telegram-группа',
            'TreeMembership' => 'доступ участника',
            default => 'семейная запись',
        };
        $name = $data['title'] ?? $data['name'] ?? $data['label'] ?? null;

        return $label.($name ? ' — '.$name : " #{$change->subject_id}");
    }

    private function changeAction(string $action): string
    {
        return match ($action) {
            'created' => 'добавлено',
            'updated' => 'изменено',
            'deleted' => 'удалено',
            'restored' => 'восстановлено',
            default => $action,
        };
    }

    private function changedFields(ChangeLog $change): string
    {
        $labels = [
            'first_name' => 'имя',
            'middle_name' => 'отчество',
            'last_name' => 'фамилия',
            'maiden_name' => 'девичья фамилия',
            'gender' => 'пол',
            'birth_date' => 'дата рождения',
            'death_date' => 'дата смерти',
            'birth_place' => 'место рождения',
            'death_place' => 'место смерти',
            'burial_place' => 'место захоронения',
            'current_city' => 'город проживания',
            'current_address' => 'адрес',
            'occupation' => 'род занятий',
            'bio' => 'биография',
            'title' => 'название',
            'description' => 'описание',
            'status' => 'статус',
            'role' => 'роль',
            'person_id' => 'привязка к человеку',
            'is_active' => 'доступ',
        ];

        return collect(array_keys($change->after ?? $change->before ?? []))
            ->reject(fn (string $field): bool => in_array($field, [
                'id',
                'tree_id',
                'user_id',
                'created_at',
                'updated_at',
            ], true))
            ->map(fn (string $field): string => $labels[$field] ?? str_replace('_', ' ', $field))
            ->unique()
            ->take(6)
            ->implode(', ');
    }
}
