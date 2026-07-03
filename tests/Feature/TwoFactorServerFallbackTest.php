<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorCodeDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TwoFactorServerFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_code_is_written_to_private_storage_when_no_channel_is_available(): void
    {
        Storage::fake('local');
        config()->set('mail.default', 'log');
        $user = User::factory()->create(['is_super_admin' => true]);

        $delivered = app(TwoFactorCodeDelivery::class)
            ->deliver($user, '123456', now()->addMinutes(10));

        $this->assertTrue($delivered);
        Storage::disk('local')->assertExists("2fa/superadmin-{$user->id}.txt");
        $this->assertStringContainsString(
            'Код: 123456',
            Storage::disk('local')->get("2fa/superadmin-{$user->id}.txt"),
        );
    }

    public function test_server_fallback_is_never_created_for_a_regular_user(): void
    {
        Storage::fake('local');
        config()->set('mail.default', 'log');
        $user = User::factory()->create();

        $delivered = app(TwoFactorCodeDelivery::class)
            ->deliver($user, '123456', now()->addMinutes(10));

        $this->assertFalse($delivered);
        Storage::disk('local')->assertMissing("2fa/superadmin-{$user->id}.txt");
    }

    public function test_server_fallback_is_deleted_after_successful_verification(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['is_super_admin' => true]);
        Storage::disk('local')->put("2fa/superadmin-{$user->id}.txt", 'temporary code');

        $response = $this->actingAs($user)
            ->withSession([
                'two_factor_code_hash' => password_hash('123456', PASSWORD_BCRYPT),
                'two_factor_expires_at' => now()->addMinutes(10)->timestamp,
            ])
            ->post('/two-factor/challenge', ['code' => '123456']);

        $response->assertRedirect('/trees');
        Storage::disk('local')->assertMissing("2fa/superadmin-{$user->id}.txt");
    }
}
