<?php

namespace App\Services;

use App\Models\FamilyTree;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CustomDomainService
{
    public function normalise(?string $value): ?string
    {
        $value = mb_strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '://')) {
            $value = (string) parse_url($value, PHP_URL_HOST);
        }
        $value = trim($value, ". \t\n\r\0\x0B");
        if (function_exists('idn_to_ascii')) {
            $value = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $value;
        }

        if (
            strlen($value) > 253
            || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value)
        ) {
            throw ValidationException::withMessages([
                'primary_domain' => 'Укажите домен без пути, например family.example.com.',
            ]);
        }

        $reserved = collect(config('platform.domains', []))
            ->push(parse_url((string) config('app.url'), PHP_URL_HOST))
            ->filter()
            ->map(fn ($domain): string => mb_strtolower((string) $domain));
        if ($reserved->contains($value)) {
            throw ValidationException::withMessages([
                'primary_domain' => 'Основной домен платформы нельзя назначить отдельному дереву.',
            ]);
        }

        return $value;
    }

    public function prepare(FamilyTree $tree): FamilyTree
    {
        $domain = $this->normalise($tree->primary_domain);
        if (! $domain) {
            $tree->updateQuietly([
                'primary_domain' => null,
                'domain_status' => 'not_configured',
                'domain_verification_token' => null,
                'domain_verified_at' => null,
                'domain_ssl_status' => 'unknown',
                'domain_checked_at' => now(),
                'domain_last_error' => null,
            ]);

            return $tree->fresh();
        }

        $duplicate = FamilyTree::query()
            ->whereRaw('LOWER(primary_domain) = ?', [$domain])
            ->whereKeyNot($tree->id)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'primary_domain' => 'Этот домен уже подключён к другому дереву.',
            ]);
        }

        $tree->updateQuietly([
            'primary_domain' => $domain,
            'domain_status' => 'pending_dns',
            'domain_verification_token' => Str::random(48),
            'domain_verified_at' => null,
            'domain_ssl_status' => 'unknown',
            'domain_checked_at' => now(),
            'domain_last_error' => null,
        ]);

        return $tree->fresh();
    }

    /**
     * @return array{verified: bool, dns: array<int, string>, ssl: string, error: ?string}
     */
    public function verify(FamilyTree $tree): array
    {
        if (! $tree->primary_domain || ! $tree->domain_verification_token) {
            throw ValidationException::withMessages([
                'primary_domain' => 'Сначала сохраните собственный домен.',
            ]);
        }

        $records = [];
        $txtNames = [
            '_idommoy-verification.'.$tree->primary_domain,
            $tree->primary_domain,
        ];
        foreach ($txtNames as $name) {
            $records = [...$records, ...$this->txtRecords($name)];
        }
        $expected = 'idommoy-verification='.$tree->domain_verification_token;
        $verified = collect($records)->contains(
            fn (string $record): bool => hash_equals($expected, trim($record)),
        );

        if (! $verified) {
            $tree->updateQuietly([
                'domain_status' => 'pending_dns',
                'domain_checked_at' => now(),
                'domain_last_error' => 'TXT-запись подтверждения пока не найдена.',
            ]);

            return [
                'verified' => false,
                'dns' => $records,
                'ssl' => 'unknown',
                'error' => 'TXT-запись подтверждения пока не найдена.',
            ];
        }

        $ssl = $this->checkSsl($tree->primary_domain);
        $tree->updateQuietly([
            'domain_status' => $ssl === 'ready' ? 'active' : 'verified',
            'domain_verified_at' => $tree->domain_verified_at ?: now(),
            'domain_ssl_status' => $ssl,
            'domain_checked_at' => now(),
            'domain_last_error' => $ssl === 'ready'
                ? null
                : 'Домен подтверждён, но HTTPS-сертификат ещё не готов.',
        ]);

        return [
            'verified' => true,
            'dns' => $records,
            'ssl' => $ssl,
            'error' => $ssl === 'ready' ? null : 'HTTPS-сертификат ещё не готов.',
        ];
    }

    public function instructions(FamilyTree $tree): string
    {
        if (! $tree->primary_domain || ! $tree->domain_verification_token) {
            return "1. Введите домен без https:// и без пути, например family.example.com.\n"
                ."2. Нажмите «Сохранить изменения».\n"
                ."3. После сохранения здесь появятся точные TXT- и DNS-записи.\n"
                ."4. Добавьте домен в Plesk как alias без перенаправления 301.\n"
                ."5. Выпустите для него сертификат Let's Encrypt и нажмите «Проверить DNS и SSL».";
        }

        $platformDomain = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'idommoy.com';

        return "1. Добавьте TXT для _idommoy-verification.{$tree->primary_domain}\n"
            ."   Значение: idommoy-verification={$tree->domain_verification_token}\n"
            ."2. Для поддомена добавьте CNAME {$tree->primary_domain} → {$platformDomain}.\n"
            ."   Для корневого домена добавьте A/AAAA на IP-адрес сервера из Plesk.\n"
            ."3. В Plesk добавьте {$tree->primary_domain} как domain alias основного сайта.\n"
            ."   Не включайте перенаправление 301: адрес семьи должен остаться в браузере.\n"
            ."4. Включите {$tree->primary_domain} в сертификат Let's Encrypt.\n"
            ."5. Подождите обновления DNS и нажмите «Проверить DNS и SSL».\n\n"
            ."Текущий статус: {$tree->domain_status}; HTTPS: {$tree->domain_ssl_status}.";
    }

    private function checkSsl(string $domain): string
    {
        if (function_exists('stream_socket_client')) {
            try {
                $context = stream_context_create([
                    'ssl' => [
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'peer_name' => $domain,
                        'SNI_enabled' => true,
                    ],
                ]);
                $socket = @stream_socket_client(
                    "ssl://{$domain}:443",
                    $errno,
                    $error,
                    8,
                    STREAM_CLIENT_CONNECT,
                    $context,
                );
                if (is_resource($socket)) {
                    fclose($socket);

                    return 'ready';
                }
            } catch (Throwable) {
                // Some hosters disable socket functions. HTTP below provides
                // the same certificate validation without relying on sockets.
            }
        }

        try {
            Http::timeout(8)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->get("https://{$domain}");

            return 'ready';
        } catch (Throwable) {
            return 'missing';
        }
    }

    /**
     * @return array<int, string>
     */
    private function txtRecords(string $name): array
    {
        if (function_exists('dns_get_record')) {
            try {
                $records = collect(@dns_get_record($name, DNS_TXT) ?: [])
                    ->pluck('txt')
                    ->filter(fn ($value): bool => is_string($value) && $value !== '')
                    ->values()
                    ->all();
                if ($records !== []) {
                    return $records;
                }
            } catch (Throwable) {
                // Continue with a public DNS-over-HTTPS resolver.
            }
        }

        try {
            $response = Http::acceptJson()
                ->timeout(8)
                ->connectTimeout(5)
                ->get('https://dns.google/resolve', [
                    'name' => $name,
                    'type' => 'TXT',
                ]);
            if (! $response->successful()) {
                return [];
            }

            return collect($response->json('Answer', []))
                ->where('type', 16)
                ->pluck('data')
                ->filter(fn ($value): bool => is_string($value))
                ->map(function (string $value): string {
                    preg_match_all('/"((?:\\\\.|[^"])*)"/', $value, $parts);

                    return $parts[1] === []
                        ? trim($value, '"')
                        : implode('', array_map('stripcslashes', $parts[1]));
                })
                ->filter()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
