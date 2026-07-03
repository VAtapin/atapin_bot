<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TotpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_connect_any_totp_compatible_authenticator(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);

        $this->actingAs($user)
            ->get('/account/two-factor/setup')
            ->assertOk()
            ->assertSee('Яндекс ID')
            ->assertSee('2FAS');

        $secret = (string) session('totp_setup_secret');
        $this->assertNotSame('', $secret);

        $this->post('/account/two-factor/confirm', [
            'code' => app(TotpService::class)->currentCode($secret),
        ])->assertRedirect('/account');

        $user->refresh();
        $this->assertTrue($user->two_factor_enabled);
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertNotSame(
            $secret,
            DB::table('users')->where('id', $user->id)->value('two_factor_secret'),
        );
    }

    public function test_confirmed_totp_code_completes_login_challenge(): void
    {
        Storage::fake('local');
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create([
            'is_super_admin' => true,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession([
                'two_factor_code_hash' => Hash::make('999999'),
                'two_factor_expires_at' => now()->addMinutes(10)->timestamp,
                'two_factor_intended_url' => '/admin',
            ])
            ->post('/two-factor/challenge', [
                'code' => app(TotpService::class)->currentCode($secret),
            ])
            ->assertRedirect('/admin')
            ->assertSessionHas('two_factor_user_id', $user->id);

        $this->assertNotNull($user->fresh()->two_factor_last_used_counter);
    }

    public function test_same_totp_code_cannot_be_reused(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $code = $totp->currentCode($secret);
        $counter = $totp->verify($secret, $code);

        $this->assertIsInt($counter);
        $this->assertFalse($totp->verify($secret, $code, $counter));
    }
}
