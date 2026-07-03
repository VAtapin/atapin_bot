<?php

namespace App\Services;

use App\Models\Congratulation;
use App\Models\ExternalIdentity;
use App\Models\FamilyTree;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\TreeMembership;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class CongratulationService
{
    public function __construct(private readonly TelegramBot $bot) {}

    /**
     * @return Collection<int, Congratulation>
     */
    public function send(
        FamilyTree $tree,
        User $sender,
        TreeMembership $membership,
        string $occasion,
        string $message,
        ?Person $recipient = null,
        ?Partnership $partnership = null,
    ): Collection {
        abort_unless($membership->status === 'approved' || $sender->is_super_admin, 403);
        abort_unless(($tree->settings['allow_congratulations'] ?? true) !== false, 403, 'Поздравления отключены владельцем дерева.');
        abort_unless($recipient || $partnership, 422, 'Получатель не выбран.');
        if ($recipient) {
            abort_unless((int) $recipient->tree_id === (int) $tree->id, 404);
        }
        if ($partnership) {
            abort_unless((int) $partnership->tree_id === (int) $tree->id, 404);
        }

        $recipients = $recipient
            ? collect([$recipient])
            : collect([$partnership->partnerOne, $partnership->partnerTwo])->filter();

        $items = DB::transaction(function () use (

            $sender,
            $membership,
            $occasion,
            $message,
            $partnership,
            $recipients,
        ): Collection {
            return $recipients->map(function (Person $person) use (

                $sender,
                $membership,
                $occasion,
                $message,
                $partnership,
            ): Congratulation {
                $congratulation = Congratulation::query()->create([
                    'sender_user_id' => $sender->id,
                    'sender_person_id' => $membership->person_id,
                    'recipient_person_id' => $person->id,
                    'partnership_id' => $partnership?->id,
                    'occasion' => $occasion,
                    'message' => trim($message),
                    'site_status' => 'delivered',
                    'telegram_status' => 'not_available',
                ]);

                return $congratulation;
            });
        });

        $items->each(fn (Congratulation $item) => $this->deliverTelegram($item, $tree, $sender));

        return $items->map->fresh();
    }

    private function deliverTelegram(
        Congratulation $congratulation,
        FamilyTree $tree,
        User $sender,
    ): void {
        if (($tree->settings['allow_telegram_congratulations'] ?? true) === false) {
            $congratulation->update(['telegram_status' => 'disabled']);

            return;
        }

        $userIds = TreeMembership::query()
            ->where('tree_id', $tree->id)
            ->where('person_id', $congratulation->recipient_person_id)
            ->where('status', 'approved')
            ->pluck('user_id');
        $identities = ExternalIdentity::query()
            ->whereIn('user_id', $userIds)
            ->where('provider', 'telegram')
            ->pluck('provider_user_id');

        if ($identities->isEmpty()) {
            return;
        }

        $delivered = false;
        $lastError = null;
        foreach ($identities as $telegramId) {
            try {
                $this->bot->sendMessage(
                    $telegramId,
                    "💌 <b>Новое семейное поздравление</b>\n\n"
                    .'<b>От:</b> '.e($sender->name)."\n\n"
                    .e($congratulation->message),
                );
                $delivered = true;
            } catch (Throwable $exception) {
                report($exception);
                $lastError = $exception->getMessage();
            }
        }

        $congratulation->update([
            'telegram_status' => $delivered ? 'delivered' : 'failed',
            'telegram_error' => $delivered ? null : mb_substr((string) $lastError, 0, 2000),
            'telegram_delivered_at' => $delivered ? now() : null,
        ]);
    }
}
