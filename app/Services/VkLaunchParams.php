<?php

namespace App\Services;

use InvalidArgumentException;

class VkLaunchParams
{
    public function validate(string $launchParams, string $secret): array
    {
        parse_str($launchParams, $data);
        $sign = (string) ($data['sign'] ?? '');

        if ($sign === '' || $secret === '') {
            throw new InvalidArgumentException('VK не передал корректную подпись.');
        }

        $signedValues = collect($data)
            ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'vk_'))
            ->sortKeys()
            ->all();
        $signed = http_build_query($signedValues, '', '&', PHP_QUERY_RFC3986);
        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signed, $secret, true),
        ), '+/', '-_'), '=');

        if (! hash_equals($expected, $sign)) {
            throw new InvalidArgumentException('Подпись VK Mini App недействительна.');
        }

        if (isset($data['vk_ts']) && time() - (int) $data['vk_ts'] > 86400) {
            throw new InvalidArgumentException('Сессия VK Mini App устарела.');
        }

        return $data;
    }
}
