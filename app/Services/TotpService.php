<?php

namespace App\Services;

use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;

class TotpService
{
    public function generateSecret(): string
    {
        return app(Google2FA::class)->generateSecretKey(32);
    }

    public function qrCode(string $account, string $secret): string
    {
        return app(Google2FAQRCode::class)->getQRCodeInline(
            (string) config('platform.name', 'Я и дом мой'),
            $account,
            $secret,
            240,
        );
    }

    public function verify(
        string $secret,
        string $code,
        ?int $lastUsedCounter = null,
    ): int|false {
        $google2fa = app(Google2FA::class);
        $result = $lastUsedCounter === null
            ? $google2fa->verifyKey($secret, $code, 1)
            : $google2fa->verifyKeyNewer($secret, $code, $lastUsedCounter, 1);

        return $result === false ? false : (int) $result;
    }

    public function currentCode(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }
}
