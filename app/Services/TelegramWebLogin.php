<?php

namespace App\Services;

use App\Models\TelegramLoginToken;
use App\Models\TelegramUser;

class TelegramWebLogin
{
    public function createUrl(TelegramUser $user): string
    {
        $plainToken = bin2hex(random_bytes(32));

        TelegramLoginToken::query()->create([
            'telegram_user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addMinutes(15),
        ]);

        TelegramLoginToken::query()
            ->where('telegram_user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '<', now())
            ->delete();

        return route('telegram.link-login', ['token' => $plainToken]);
    }
}
