<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class PublicHomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_localized_homepage_uses_structured_sections(): void
    {
        $this->get('/ru')
            ->assertOk()
            ->assertSee('home-section--hero', false)
            ->assertSee('Семейное дерево онлайн для вашей семьи');
    }

    public function test_locale_domains_redirect_to_canonical_locale_path(): void
    {
        $middleware = app(SetLocale::class);
        $deResponse = $middleware->handle(
            Request::create('https://idommoy.de/'),
            fn () => response('unexpected'),
        );
        $ruResponse = $middleware->handle(
            Request::create('https://idommoy.ru/page/about'),
            fn () => response('unexpected'),
        );

        $this->assertSame(301, $deResponse->getStatusCode());
        $this->assertSame('https://idommoy.com/de', $deResponse->headers->get('Location'));
        $this->assertSame(301, $ruResponse->getStatusCode());
        $this->assertSame('https://idommoy.com/ru/page/about', $ruResponse->headers->get('Location'));
    }

    public function test_analytics_consent_is_set_by_the_server(): void
    {
        $this->postJson('/privacy/analytics-consent', ['consent' => 'granted'])
            ->assertOk()
            ->assertJsonPath('consent', 'granted')
            ->assertCookie('analytics_consent');
    }
}
