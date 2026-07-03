<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\ExternalIdentity;
use App\Models\TelegramAccountLinkToken;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramAccountLinkService
{
    public function __construct(
        private readonly ExternalIdentityService $identities,
        private readonly UserMergeService $users,
    ) {}

    public function createDeepLink(User $user): string
    {
        $plainToken = Str::lower(Str::random(32));

        TelegramAccountLinkToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMinutes(15),
        ]);
        TelegramAccountLinkToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '<', now())
            ->delete();

        $username = ltrim((string) config('services.telegram.bot_username'), '@');
        abort_if($username === '', 503, 'Имя Telegram-бота не настроено.');

        return "https://t.me/{$username}?start=link_{$plainToken}";
    }

    public function consume(
        string $plainToken,
        TelegramUser $telegramUser,
        array $profile,
    ): User {
        return DB::transaction(function () use ($plainToken, $telegramUser, $profile): User {
            $link = TelegramAccountLinkToken::query()
                ->with('user')
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if (! $link || $link->used_at || $link->expires_at->isPast() || ! $link->user?->is_active) {
                throw ValidationException::withMessages([
                    'token' => 'Ссылка привязки недействительна или уже использована.',
                ]);
            }

            $target = $link->user;
            $identity = ExternalIdentity::query()
                ->where('provider', 'telegram')
                ->where('provider_user_id', (string) $telegramUser->telegram_user_id)
                ->lockForUpdate()
                ->first();
            $source = $identity?->user ?: $telegramUser->user;

            if ($source && ! $source->is($target)) {
                $target = $this->users->merge($source, $target, $target);
            }

            $target = $this->identities->resolve(
                'telegram',
                $telegramUser->telegram_user_id,
                $profile,
                $target,
            );
            $telegramUser->updateQuietly([
                'user_id' => $target->id,
                'current_tree_id' => $telegramUser->current_tree_id ?: $target->last_tree_id,
                'status' => $target->memberships()->where('status', 'approved')->exists()
                    ? 'approved'
                    : ($telegramUser->status ?: 'pending'),
            ]);
            $link->update(['used_at' => now()]);

            ChangeLog::query()->create([
                'user_id' => $target->id,
                'action' => 'telegram_account_linked',
                'subject_type' => User::class,
                'subject_id' => $target->id,
                'after' => ['telegram_user_id' => $telegramUser->telegram_user_id],
            ]);

            return $target;
        });
    }
}
