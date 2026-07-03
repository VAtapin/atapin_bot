<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalIdentityService
{
    public function resolve(
        string $provider,
        string|int $providerUserId,
        array $profile,
        ?User $linkTo = null,
    ): User {
        return DB::transaction(function () use ($provider, $providerUserId, $profile, $linkTo): User {
            $identity = ExternalIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', (string) $providerUserId)
                ->first();

            if ($identity) {
                if ($linkTo && $identity->user_id !== $linkTo->id) {
                    throw new \RuntimeException('Этот мессенджер уже подключён к другой учётной записи.');
                }
                $identity->update([
                    'username' => $profile['username'] ?? $identity->username,
                    'profile' => $profile,
                    'last_login_at' => now(),
                ]);

                return $identity->user;
            }

            if ($linkTo) {
                $linkTo->externalIdentities()->create([
                    'provider' => $provider,
                    'provider_user_id' => (string) $providerUserId,
                    'username' => $profile['username'] ?? null,
                    'provider_email' => $profile['email'] ?? null,
                    'profile' => $profile,
                    'last_login_at' => now(),
                    'verified_at' => now(),
                ]);

                return $linkTo;
            }

            $name = trim(($profile['first_name'] ?? '').' '.($profile['last_name'] ?? ''))
                ?: ($profile['name'] ?? ucfirst($provider).' user');
            $user = User::query()->create([
                'name' => $name,
                'email' => $provider.'_'.preg_replace('/\D+/', '', (string) $providerUserId)
                    .'@idommoy.local',
                'password' => Str::random(64),
                'is_active' => true,
            ]);

            $user->externalIdentities()->create([
                'provider' => $provider,
                'provider_user_id' => (string) $providerUserId,
                'username' => $profile['username'] ?? null,
                'provider_email' => $profile['email'] ?? null,
                'profile' => $profile,
                'last_login_at' => now(),
                'verified_at' => now(),
            ]);

            return $user;
        });
    }
}
