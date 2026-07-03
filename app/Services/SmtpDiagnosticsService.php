<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\SmtpTestLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SmtpDiagnosticsService
{
    /**
     * @return array<string, mixed>
     */
    public function test(string $recipient, ?int $userId = null): array
    {
        $diagnostics = [
            'host' => (string) PlatformSetting::value('smtp_host'),
            'port' => (int) PlatformSetting::value('smtp_port', 587),
            'encryption' => (string) PlatformSetting::value('smtp_encryption', 'tls'),
            'username' => $this->masked((string) PlatformSetting::value('smtp_username')),
            'from' => (string) PlatformSetting::value('smtp_from_address'),
            'recipient' => $recipient,
            'dns' => false,
            'tcp' => false,
            'accepted_by_smtp' => false,
        ];
        $stage = 'configuration';
        $messageId = Str::uuid().'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'idommoy.local');

        try {
            abort_unless(PlatformSetting::value('smtp_enabled', false), 422, 'SMTP выключен в настройках.');
            foreach (['smtp_host', 'smtp_username', 'smtp_password', 'smtp_from_address'] as $key) {
                if (blank(PlatformSetting::value($key))) {
                    throw new RuntimeException("Не заполнена настройка {$key}.");
                }
            }

            $stage = 'dns';
            $addresses = gethostbynamel($diagnostics['host']) ?: [];
            if ($addresses === []) {
                throw new RuntimeException('SMTP host не найден через DNS.');
            }
            $diagnostics['dns'] = true;
            $diagnostics['resolved_addresses'] = $addresses;
            $fromDomain = str_contains($diagnostics['from'], '@')
                ? substr(strrchr($diagnostics['from'], '@'), 1)
                : null;
            if ($fromDomain) {
                $rootTxt = collect(@dns_get_record($fromDomain, DNS_TXT) ?: [])
                    ->pluck('txt')
                    ->filter()
                    ->values();
                $dmarcTxt = collect(@dns_get_record('_dmarc.'.$fromDomain, DNS_TXT) ?: [])
                    ->pluck('txt')
                    ->filter()
                    ->values();
                $diagnostics['spf'] = $rootTxt->first(
                    fn (string $record): bool => str_starts_with(mb_strtolower($record), 'v=spf1'),
                );
                $diagnostics['dmarc'] = $dmarcTxt->first(
                    fn (string $record): bool => str_starts_with(mb_strtolower($record), 'v=dmarc1'),
                );
                $diagnostics['dkim'] = 'Проверяется по селектору, указанному почтовым провайдером.';
            }

            $stage = 'tcp';
            $scheme = $diagnostics['encryption'] === 'ssl' ? 'ssl' : 'tcp';
            $errno = 0;
            $error = '';
            $socket = @stream_socket_client(
                "{$scheme}://{$diagnostics['host']}:{$diagnostics['port']}",
                $errno,
                $error,
                min((int) PlatformSetting::value('smtp_timeout', 15), 20),
            );
            if (! is_resource($socket)) {
                throw new RuntimeException("SMTP-порт недоступен: {$error} ({$errno}).");
            }
            fclose($socket);
            $diagnostics['tcp'] = true;

            $stage = 'authentication_and_send';
            app(PlatformMailConfigurator::class)->apply();
            Mail::raw(
                "Проверка SMTP платформы «Я и дом мой».\n\n"
                ."Идентификатор: {$messageId}\n"
                .'Время: '.now()->toIso8601String()."\n\n"
                .'Если письмо не видно, проверьте папки «Спам» и «Вся почта».',
                function ($message) use ($recipient, $messageId): void {
                    $message
                        ->to($recipient)
                        ->subject('Проверка SMTP — Я и дом мой');
                    $message->getHeaders()->addIdHeader('Message-ID', $messageId);
                },
            );
            $diagnostics['accepted_by_smtp'] = true;

            SmtpTestLog::query()->create([
                'user_id' => $userId,
                'recipient' => $recipient,
                'status' => 'accepted',
                'stage' => 'completed',
                'message_id' => $messageId,
                'diagnostics' => $diagnostics,
            ]);

            return [
                'status' => 'accepted',
                'message_id' => $messageId,
                'diagnostics' => $diagnostics,
                'message' => 'SMTP-сервер принял письмо. Это ещё не подтверждает доставку во входящие.',
            ];
        } catch (Throwable $exception) {
            $error = $this->sanitiseError($exception->getMessage());
            SmtpTestLog::query()->create([
                'user_id' => $userId,
                'recipient' => $recipient,
                'status' => 'failed',
                'stage' => $stage,
                'message_id' => $messageId,
                'diagnostics' => $diagnostics,
                'error' => $error,
            ]);
            throw new RuntimeException("Этап «{$stage}»: {$error}", previous: $exception);
        }
    }

    private function masked(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (str_contains($value, '@')) {
            [$name, $domain] = explode('@', $value, 2);

            return mb_substr($name, 0, 2).'***@'.$domain;
        }

        return mb_substr($value, 0, 2).'***';
    }

    private function sanitiseError(string $message): string
    {
        $password = (string) PlatformSetting::value('smtp_password');

        return mb_substr($password !== '' ? str_replace($password, '***', $message) : $message, 0, 4000);
    }
}
