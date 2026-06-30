<?php

namespace App\Services;

use InvalidArgumentException;

class TelegramInitData
{
    public function validate(string $initData, string $botToken, int $maxAge = 86400): array
    {
        if ($initData === '' || $botToken === '') {
            throw new InvalidArgumentException('Telegram initData is missing.');
        }

        parse_str($initData, $data);
        $hash = $data['hash'] ?? null;
        unset($data['hash'], $data['signature']);

        if (! is_string($hash)) {
            throw new InvalidArgumentException('Telegram initData hash is missing.');
        }

        ksort($data);
        $checkString = collect($data)
            ->map(fn (mixed $value, string $key): string => $key.'='.$value)
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $checkString, $secretKey);

        if (! hash_equals($expectedHash, $hash)) {
            throw new InvalidArgumentException('Telegram initData signature is invalid.');
        }

        $authDate = (int) ($data['auth_date'] ?? 0);

        if ($authDate <= 0 || abs(time() - $authDate) > $maxAge) {
            throw new InvalidArgumentException('Telegram initData has expired.');
        }

        $user = json_decode($data['user'] ?? '{}', true);

        if (! is_array($user) || ! isset($user['id'])) {
            throw new InvalidArgumentException('Telegram user is missing.');
        }

        return ['user' => $user, 'raw' => $data];
    }
}
