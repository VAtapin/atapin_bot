<?php

use App\Http\Controllers\FamilyAuthController;
use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\TelegramLinkLoginController;
use App\Http\Controllers\TelegramLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/family');
});

Route::get('/family', [MiniAppController::class, 'index'])->name('family.app');
Route::get('/family/person/{person}', [MiniAppController::class, 'index'])
    ->name('family.person');
Route::get('/auth/telegram', [TelegramLoginController::class, 'redirect'])
    ->name('telegram.login');
Route::get('/auth/telegram/callback', [TelegramLoginController::class, 'callback'])
    ->name('telegram.login.callback');
Route::get('/auth/telegram/link/{token}', TelegramLinkLoginController::class)
    ->name('telegram.link-login');
Route::post('/auth/telegram/logout', [TelegramLoginController::class, 'logout'])
    ->name('telegram.logout');
Route::post('/family/login', [FamilyAuthController::class, 'login'])
    ->name('family.login');
Route::post('/family/logout', [FamilyAuthController::class, 'logout'])
    ->name('family.logout');
