<?php

namespace Tests\Feature;

use App\Models\FamilyTree;
use App\Services\CustomTelegramBotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomTelegramBotTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_installs_full_command_menu_and_tracks_webhook(): void
    {
        $tree = FamilyTree::query()->firstOrFail();
        $tree->plan->update(['custom_bot' => true]);
        $tree->update(['custom_bot_token' => '123:family-token']);
        $webhookUrl = route('telegram.custom-webhook', ['tree' => $tree->slug]);

        Http::fake(function (Request $request) use ($webhookUrl) {
            $method = str($request->url())->afterLast('/')->toString();

            return Http::response([
                'ok' => true,
                'result' => match ($method) {
                    'getMe' => ['username' => 'family_bot'],
                    'getWebhookInfo' => [
                        'url' => $webhookUrl,
                        'pending_update_count' => 0,
                    ],
                    default => true,
                },
            ]);
        });

        app(CustomTelegramBotService::class)->configure($tree->fresh('plan'));

        $this->assertSame('active', $tree->fresh()->custom_bot_status);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setChatMenuButton')
            && data_get($request->data(), 'menu_button.type') === 'commands');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/setMyCommands')
            && count($request->data()['commands'] ?? []) >= 14);
    }
}
