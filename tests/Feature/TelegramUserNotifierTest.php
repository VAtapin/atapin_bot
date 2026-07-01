<?php

namespace Tests\Feature;

use App\Models\TelegramUser;
use App\Services\TelegramUserNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramUserNotifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_user_receive_access_notifications(): void
    {
        config()->set('services.telegram.bot_token', 'test-token');
        config()->set('services.telegram.admin_ids', ['999']);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);
        $user = TelegramUser::query()->create([
            'telegram_user_id' => 321,
            'first_name' => 'Иван',
            'status' => 'pending',
        ]);
        $notifier = app(TelegramUserNotifier::class);

        $notifier->newRequest($user);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['chat_id'] === '999'
                && $data['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'access:approve:1';
        });

        $user->status = 'approved';
        $notifier->changed($user, ['status' => 'pending']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $data['chat_id'] === 321
                && str_contains($data['text'], 'разрешён доступ');
        });
    }
}
