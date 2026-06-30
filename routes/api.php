<?php

use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

Route::middleware(['web', 'telegram.webapp'])->group(function (): void {
    Route::get('/family/tree', [MiniAppController::class, 'tree']);
    Route::get('/family/birthdays', [MiniAppController::class, 'birthdays']);
});
