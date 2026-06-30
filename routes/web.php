<?php

use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\TelegramLoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/family');
});

Route::get('/family', [MiniAppController::class, 'index'])->name('family.app');
Route::get('/auth/telegram', [TelegramLoginController::class, 'redirect'])
    ->name('telegram.login');
Route::get('/auth/telegram/callback', [TelegramLoginController::class, 'callback'])
    ->name('telegram.login.callback');
Route::post('/auth/telegram/logout', [TelegramLoginController::class, 'logout'])
    ->name('telegram.logout');
