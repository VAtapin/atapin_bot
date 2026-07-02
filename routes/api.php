<?php

use App\Http\Controllers\AccountPrivacyController;
use App\Http\Controllers\DataIssueController;
use App\Http\Controllers\FamilySelfServiceController;
use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->name('telegram.webhook');

Route::middleware(['web', 'family.tree', 'telegram.webapp'])->group(function (): void {
    Route::get('/family/tree', [MiniAppController::class, 'tree']);
    Route::get('/family/birthdays', [MiniAppController::class, 'birthdays']);
    Route::get('/family/gallery', [MiniAppController::class, 'gallery']);
    Route::post('/family/navigation', [MiniAppController::class, 'navigation']);
    Route::post('/family/issues', [DataIssueController::class, 'store']);
    Route::get('/family/privacy-export', [AccountPrivacyController::class, 'export']);
    Route::delete('/family/account', [AccountPrivacyController::class, 'destroy']);
    Route::get('/family/me', [FamilySelfServiceController::class, 'show']);
    Route::put('/family/me', [FamilySelfServiceController::class, 'update']);
    Route::delete('/family/me', [FamilySelfServiceController::class, 'destroy']);
    Route::post('/family/me/relatives', [FamilySelfServiceController::class, 'storeRelative']);
    Route::put('/family/me/relatives/{person}', [FamilySelfServiceController::class, 'updateRelative']);
    Route::delete('/family/me/relatives/{person}', [FamilySelfServiceController::class, 'destroyRelative']);
    Route::post('/family/me/albums', [FamilySelfServiceController::class, 'storeAlbum']);
    Route::put('/family/me/albums/{album}', [FamilySelfServiceController::class, 'updateAlbum']);
    Route::delete('/family/me/albums/{album}', [FamilySelfServiceController::class, 'destroyAlbum']);
    Route::post('/family/me/photos', [FamilySelfServiceController::class, 'storePhoto']);
    Route::delete('/family/me/photos/{photo}', [FamilySelfServiceController::class, 'destroyPhoto']);
});
