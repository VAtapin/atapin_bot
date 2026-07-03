<?php

namespace App\Services;

use App\Models\TelegramLoginToken;
use App\Models\TelegramUser;

class TelegramWebLogin
{
    public function createUrl(
        TelegramUser $user,
        ?string $targetHost = null,
        ?string $returnPath = null,
    ): string {
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

        $path = route('telegram.link-login', ['token' => $plainToken], false);
        if ($returnPath) {
            $path .= '?'.http_build_query(['return' => $returnPath]);
        }

        return $targetHost
            ? 'https://'.$targetHost.$path
            : url($path);
    }
}
