<?php

use App\Http\Controllers\FamilyAuthController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\TelegramLinkLoginController;
use App\Http\Controllers\TelegramLoginController;
use App\Http\Controllers\TreeExportController;
use App\Http\Controllers\TreeInvitationController;
use App\Http\Controllers\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/page/{page:slug}', [PublicSiteController::class, 'page'])->name('public.page');
Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');
Route::get('/invite/{token}', TreeInvitationController::class)->name('tree.invitation');
Route::get('/media/photos/{photo}', [MediaController::class, 'photo'])
    ->middleware(['signed', 'throttle:120,1'])
    ->name('media.photo');
Route::get('/media/people/{person}', [MediaController::class, 'person'])
    ->middleware(['signed', 'throttle:120,1'])
    ->name('media.person');
Route::get('/admin/trees/{tree}/export', TreeExportController::class)
    ->middleware('auth')
    ->name('trees.export');
Route::middleware('auth')->group(function (): void {
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'show'])
        ->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'verify'])
        ->name('two-factor.verify');
});

Route::middleware('family.tree')->group(function (): void {
    Route::get('/family', [MiniAppController::class, 'index'])->name('family.app');
    Route::get('/family/person/{person}', [MiniAppController::class, 'index'])
        ->name('family.person');
    Route::get('/family/{tree:slug}', [MiniAppController::class, 'index'])
        ->name('family.tree');
    Route::get('/family/{tree:slug}/person/{person}', [MiniAppController::class, 'index'])
        ->name('family.tree.person');
});
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
