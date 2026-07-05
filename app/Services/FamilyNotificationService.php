<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\DataIssue;
use App\Models\ExternalIdentity;
use App\Models\FamilyTree;
use App\Models\Person;
use App\Models\TreeMembership;
use App\Models\User;
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
        $details = $this->changeDetails($change);
        $this->sendToManagers(
            $change->tree,
            "✏️ <b>Изменение в семейном дереве</b>\n\n"
            .'Дерево: <b>'.e($change->tree->name)."</b>\n"
            .'Кто изменил: <b>'.e($change->user?->name ?: 'не определено')."</b>\n"
            .'Что: <b>'.e($subject)."</b>\n"
            .'Действие: '.e($this->changeAction($change->action))
            .($details !== '' ? "\n\n".$details : ''),
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

        if ($type === 'TreeMembership') {
            $userName = $this->userName($data['user_id'] ?? null);
            $personName = $this->personName($data['person_id'] ?? null);

            return collect([
                'доступ участника',
                $userName,
                $personName ? 'карточка: '.$personName : null,
            ])->filter()->implode(' — ') ?: "доступ участника #{$change->subject_id}";
        }

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
        return $this->changedFieldLabels($change)->implode(', ');
    }

    private function changeDetails(ChangeLog $change): string
    {
        $before = $change->before ?? [];
        $after = $change->after ?? [];

        if ($change->action !== 'updated') {
            $source = $change->action === 'deleted' ? $before : $after;

            return collect($source)
                ->reject(fn (mixed $value, string $field): bool => $this->isHiddenChangeField($field))
                ->map(function (mixed $value, string $field): string {
                    $label = $this->fieldLabel($field);
                    $formatted = $this->formatChangeValue($field, $value);

                    return '• <b>'.e($label).'</b>: '.e($formatted);
                })
                ->filter()
                ->take(8)
                ->implode("\n");
        }

        return collect(array_keys($after))
            ->reject(fn (string $field): bool => $this->isHiddenChangeField($field))
            ->map(function (string $field) use ($before, $after): string {
                $label = $this->fieldLabel($field);
                $from = $this->formatChangeValue($field, $before[$field] ?? null);
                $to = $this->formatChangeValue($field, $after[$field] ?? null);

                if ($from === $to) {
                    return '';
                }

                return '• <b>'.e($label).'</b>: '.e($from).' → '.e($to);
            })
            ->filter()
            ->take(8)
            ->implode("\n");
    }

    private function changedFieldLabels(ChangeLog $change): \Illuminate\Support\Collection
    {
        $fields = array_unique(array_merge(
            array_keys($change->before ?? []),
            array_keys($change->after ?? []),
        ));

        return collect($fields)
            ->reject(fn (string $field): bool => $this->isHiddenChangeField($field))
            ->map(fn (string $field): string => $this->fieldLabel($field))
            ->unique();
    }

    private function fieldLabel(string $field): string
    {
        $labels = [
            'first_name' => 'имя',
            'middle_name' => 'отчество',
            'last_name' => 'фамилия',
            'maiden_name' => 'девичья фамилия',
            'married_name' => 'фамилия в браке',
            'gender' => 'пол',
            'birth_date' => 'дата рождения',
            'birth_date_precision' => 'точность даты рождения',
            'death_date' => 'дата смерти',
            'death_date_precision' => 'точность даты смерти',
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
            'user_id' => 'пользователь',
            'approved_by_user_id' => 'кто подтвердил',
            'approved_at' => 'когда подтверждён',
            'person_linked_at' => 'когда привязан к человеку',
            'is_active' => 'доступ',
            'notify_birthdays' => 'уведомлять о днях рождения',
            'birthday_notification_hour' => 'час уведомлений',
            'telegram_chat_id' => 'Telegram-чат',
            'timezone' => 'часовой пояс',
        ];

        return $labels[$field] ?? str_replace('_', ' ', $field);
    }

    private function isHiddenChangeField(string $field): bool
    {
        return in_array($field, [
            'id',
            'tree_id',
            'created_at',
            'updated_at',
            'deleted_at',
        ], true);
    }

    private function formatChangeValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'пусто';
        }

        return match ($field) {
            'role' => TreeMembership::ROLES[(string) $value] ?? (string) $value,
            'status' => match ((string) $value) {
                'approved' => 'разрешён',
                'blocked' => 'заблокирован',
                'pending' => 'ожидает подтверждения',
                default => (string) $value,
            },
            'gender' => match ((string) $value) {
                'male' => 'мужской',
                'female' => 'женский',
                'unknown' => 'не указан',
                default => (string) $value,
            },
            'person_id' => $this->personName($value) ?: '#'.$value,
            'user_id', 'approved_by_user_id' => $this->userName($value) ?: '#'.$value,
            'is_active', 'notify_birthdays' => $value ? 'включено' : 'выключено',
            default => is_bool($value) ? ($value ? 'да' : 'нет') : (string) $value,
        };
    }

    private function personName(mixed $id): ?string
    {
        if (! $id) {
            return null;
        }

        return Person::withoutGlobalScope('family_tree')->whereKey($id)->first()?->full_name;
    }

    private function userName(mixed $id): ?string
    {
        if (! $id) {
            return null;
        }

        return User::query()->whereKey($id)->value('name');
    }
}
