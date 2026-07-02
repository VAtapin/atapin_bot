<?php

namespace App\Services;

use App\Models\ExternalIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExternalIdentityService
{
    public function resolve(string $provider, string|int $providerUserId, array $profile): User
    {
        return DB::transaction(function () use ($provider, $providerUserId, $profile): User {
            $identity = ExternalIdentity::query()
                ->where('provider', $provider)
                ->where('provider_user_id', (string) $providerUserId)
                ->first();

            if ($identity) {
                $identity->update([
                    'username' => $profile['username'] ?? $identity->username,
                    'profile' => $profile,
                    'last_login_at' => now(),
                ]);

                return $identity->user;
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
                'profile' => $profile,
                'last_login_at' => now(),
            ]);

            return $user;
        });
    }
}
