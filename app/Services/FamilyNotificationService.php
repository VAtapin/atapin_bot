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
        $this->sendToManagers($tree, $text);
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
            .'Доступ: '.e($membership->status);
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

        $this->sendToManagers(
            $change->tree,
            "✏️ <b>В семейном дереве появились изменения</b>\n\n"
            .'Откройте историю изменений в админке для проверки.',
        );
    }

    public function notifyManagers(FamilyTree $tree, string $text): void
    {
        $this->sendToManagers($tree, $text);
    }

    private function sendToManagers(FamilyTree $tree, string $text): void
    {
        $userIds = $tree->memberships()
            ->where('status', 'approved')
            ->whereIn('role', ['owner', 'moderator'])
            ->pluck('user_id');
        ExternalIdentity::query()
            ->whereIn('user_id', $userIds)
            ->where('provider', 'telegram')
            ->pluck('provider_user_id')
            ->each(fn (string $telegramId) => $this->safeSend($telegramId, $text));
    }

    private function safeSend(int|string $chatId, string $text): void
    {
        if (! config('services.telegram.bot_token')) {
            return;
        }

        try {
            $this->bot->sendMessage($chatId, $text);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
