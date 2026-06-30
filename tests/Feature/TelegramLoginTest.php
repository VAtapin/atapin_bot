<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_oidc_redirect_contains_state_nonce_and_pkce(): void
    {
        config()->set('services.telegram.oidc_client_id', '123456');
        config()->set('services.telegram.oidc_client_secret', 'secret');
        config()->set('services.telegram.oidc_redirect_uri', 'https://family.example/auth/telegram/callback');

        $response = $this->get('/auth/telegram');

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://oauth.telegram.org/auth?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('nonce=', $location);
        $this->assertNotEmpty(session('telegram_oidc.state'));
        $this->assertNotEmpty(session('telegram_oidc.verifier'));
    }
}
