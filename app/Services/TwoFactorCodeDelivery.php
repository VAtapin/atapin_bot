<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TwoFactorCodeDelivery
{
    private const DIRECTORY = '2fa';

    private const LIFETIME_MINUTES = 10;

    public function deliver(
        User $user,
        string $code,
        Carbon $expiresAt,
        bool $sendRemotely = true,
    ): bool
    {
        $serverFallbackWritten = $user->is_super_admin
            && $this->writeServerFallback($user, $code, $expiresAt);

        if (! $sendRemotely) {
            return $serverFallbackWritten;
        }

        if ($this->sendByEmail($user, $code)) {
            return true;
        }

        if ($this->sendByTelegram($user, $code)) {
            return true;
        }

        return $serverFallbackWritten;
    }

    public function deleteServerFallback(User $user): void
    {
        Storage::disk('local')->delete($this->relativePath($user));
    }

    public function purgeExpiredServerFallbacks(): int
    {
        $deleted = 0;
        $expiresBefore = now()->subMinutes(self::LIFETIME_MINUTES)->timestamp;

        foreach (Storage::disk('local')->files(self::DIRECTORY) as $path) {
            if (
                ! preg_match('/^'.self::DIRECTORY.'\/superadmin-\d+\.txt$/', $path)
                || Storage::disk('local')->lastModified($path) > $expiresBefore
            ) {
                continue;
            }

            if (Storage::disk('local')->delete($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function sendByEmail(User $user, string $code): bool
    {
        if (config('mail.default') === 'log' || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            Mail::raw(
                "Код входа в «Я и дом мой»: {$code}\n\nКод действует 10 минут.",
                fn ($message) => $message->to($user->email)->subject('Код подтверждения входа'),
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function sendByTelegram(User $user, string $code): bool
    {
        $telegramId = ExternalIdentity::query()
            ->where('user_id', $user->id)
            ->where('provider', 'telegram')
            ->value('provider_user_id');

        if (! $telegramId) {
            return false;
        }

        try {
            app(TelegramBot::class)->sendMessage(
                $telegramId,
                "🔐 Код входа в «Я и дом мой»: <b>{$code}</b>\n\nКод действует 10 минут.",
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function writeServerFallback(User $user, string $code, Carbon $expiresAt): bool
    {
        $path = $this->relativePath($user);
        $written = Storage::disk('local')->put(
            $path,
            implode(PHP_EOL, [
                'Аварийный код подтверждения входа суперадминистратора',
                "Пользователь: {$user->email}",
                "Код: {$code}",
                'Действует до: '.$expiresAt->toIso8601String(),
                '',
                'После успешного входа этот файл удалится автоматически.',
            ]),
        );

        if (! $written) {
            return false;
        }

        $absolutePath = Storage::disk('local')->path($path);
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($absolutePath, 0600);
        }

        return true;
    }

    private function relativePath(User $user): string
    {
        return self::DIRECTORY."/superadmin-{$user->getKey()}.txt";
    }
}
