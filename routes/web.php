<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\FamilyAuthController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InvitationQrController;
use App\Http\Controllers\LeaveTreeManagementController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PublicAuthController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\TelegramLinkLoginController;
use App\Http\Controllers\TelegramLoginController;
use App\Http\Controllers\TreeChooserController;
use App\Http\Controllers\TreeExportController;
use App\Http\Controllers\TreeInvitationController;
use App\Http\Controllers\TwoFactorController;
use App\Models\FamilyTree;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::get('/page/{page:slug}', [PublicSiteController::class, 'page'])->name('public.page');
Route::get('/page/{page:slug}/preview', [PublicSiteController::class, 'preview'])
    ->middleware('auth')
    ->name('public.page.preview');
Route::get('/register', [RegistrationController::class, 'create'])->name('register');
Route::post('/register', [RegistrationController::class, 'store'])->name('register.store');
Route::get('/login', [PublicAuthController::class, 'create'])->name('login');
Route::post('/login', [PublicAuthController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('login.store');
Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
Route::post('/forgot-password', [PasswordResetController::class, 'email'])
    ->middleware('throttle:5,1')
    ->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
Route::post('/logout', [PublicAuthController::class, 'destroy'])->name('logout');
Route::get('/trees', TreeChooserController::class)->middleware('auth')->name('trees.choose');
Route::get('/help', HelpController::class)->middleware('auth')->name('help');
Route::get('/account', [AccountController::class, 'show'])->middleware('auth')->name('account');
Route::delete('/account/identities/{identity}', [AccountController::class, 'unlink'])
    ->middleware('auth')
    ->name('account.identities.unlink');
Route::get('/billing/{tree:slug}/{plan}/checkout', [BillingController::class, 'checkout'])
    ->middleware('auth')
    ->name('billing.checkout');
Route::get('/billing/{tree:slug}/return', [BillingController::class, 'returned'])
    ->middleware('auth')
    ->name('billing.return');
Route::get('/tree-management/leave', LeaveTreeManagementController::class)
    ->middleware('auth')
    ->name('tree.management.leave');
Route::get('/access/pending', function (Request $request) {
    $tree = $request->filled('tree')
        ? FamilyTree::query()->where('slug', $request->string('tree'))->first()
        : null;

    return view('public.pending', compact('tree'));
})->name('access.pending');
Route::get('/invite/{token}', TreeInvitationController::class)->name('tree.invitation');
Route::get('/invitations/{invitation}/qr', InvitationQrController::class)
    ->middleware('auth')
    ->name('tree.invitation.qr');
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

Route::get('/family', [MiniAppController::class, 'index'])->name('family.app');
Route::middleware('family.tree')->group(function (): void {
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
