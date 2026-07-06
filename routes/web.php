<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnalyticsConsentController;
use App\Http\Controllers\AnalyticsEventController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\FamilyAuthController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InvitationQrController;
use App\Http\Controllers\LeaveTreeManagementController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\MiniAppController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PendingAnalyticsEventsController;
use App\Http\Controllers\PrivacyConsentController;
use App\Http\Controllers\PublicAuthController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TelegramAccountLinkController;
use App\Http\Controllers\TelegramLinkLoginController;
use App\Http\Controllers\TelegramLoginController;
use App\Http\Controllers\TotpController;
use App\Http\Controllers\TreeChooserController;
use App\Http\Controllers\TreeExportController;
use App\Http\Controllers\TreeInvitationController;
use App\Http\Controllers\TreePreviewController;
use App\Http\Controllers\TwoFactorController;
use App\Models\FamilyTree;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');
Route::post('/analytics/events', AnalyticsEventController::class)
    ->middleware('throttle:120,1')
    ->name('analytics.event');
Route::post('/privacy/analytics-consent', AnalyticsConsentController::class)
    ->middleware('throttle:20,1')
    ->name('analytics.consent');
Route::get('/analytics/pending', PendingAnalyticsEventsController::class)
    ->middleware(['auth', 'throttle:30,1'])
    ->name('analytics.pending');

Route::get('/', function (Request $request) {
    if ($tree = $request->attributes->get('familyTree')) {
        return app(MiniAppController::class)->index($request, $tree);
    }

    return redirect()->route('home', ['locale' => app()->getLocale()]);
});
Route::get('/person/{person}', function (Request $request, Person $person) {
    $tree = $request->attributes->get('familyTree');
    abort_unless($tree && (
        $request->attributes->has('familySubdomainTree')
        || $request->attributes->has('customDomainTree')
    ), 404);

    return app(MiniAppController::class)->index($request, $tree, $person);
})->middleware('family.tree')->name('family.domain.person');
Route::prefix('{locale}')
    ->where(['locale' => 'ru|de|en|uk'])
    ->group(function (): void {
        Route::get('/', [PublicSiteController::class, 'home'])->name('home');
        Route::get('/home-preview', [PublicSiteController::class, 'homePreview'])
            ->middleware('auth')
            ->name('home.preview');
        Route::get('/faq', FaqController::class)->name('faq');
        Route::get('/page/{slug}', [PublicSiteController::class, 'page'])->name('public.page');
        Route::get('/page/{slug}/preview', [PublicSiteController::class, 'preview'])
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
    });

foreach ([
    '/faq' => 'faq',
    '/register' => 'register',
    '/login' => 'login',
    '/forgot-password' => 'password.request',
] as $legacyPath => $routeName) {
    Route::get($legacyPath, fn () => redirect()->route($routeName, ['locale' => app()->getLocale()]));
}
Route::get('/page/{slug}', fn (string $slug) => redirect()->route('public.page', [
    'locale' => app()->getLocale(),
    'slug' => $slug,
]));
Route::post('/register', [RegistrationController::class, 'store']);
Route::post('/login', [PublicAuthController::class, 'store'])->middleware('throttle:20,1');
Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'update']);
Route::post('/logout', [PublicAuthController::class, 'destroy'])->name('logout');
Route::get('/privacy-consent', [PrivacyConsentController::class, 'show'])->middleware('auth')->name('privacy-consent.show');
Route::post('/privacy-consent', [PrivacyConsentController::class, 'store'])->middleware(['auth', 'throttle:10,1'])->name('privacy-consent.store');
Route::get('/trees', TreeChooserController::class)->middleware('auth')->name('trees.choose');
Route::get('/help', HelpController::class)->middleware('auth')->name('help');
Route::get('/account', [AccountController::class, 'show'])->middleware('auth')->name('account');
Route::delete('/account/identities/{identity}', [AccountController::class, 'unlink'])->middleware('auth')->name('account.identities.unlink');
Route::post('/account/telegram/connect', TelegramAccountLinkController::class)->middleware(['auth', 'throttle:5,1'])->name('account.telegram.connect');
Route::get('/account/two-factor/setup', [TotpController::class, 'setup'])->middleware('auth')->name('totp.setup');
Route::post('/account/two-factor/confirm', [TotpController::class, 'confirm'])->middleware(['auth', 'throttle:10,1'])->name('totp.confirm');
Route::delete('/account/two-factor', [TotpController::class, 'destroy'])->middleware(['auth', 'throttle:10,1'])->name('totp.destroy');
Route::get('/billing/{tree:slug}/{plan}/checkout', [BillingController::class, 'checkout'])
    ->middleware('auth')
    ->name('billing.checkout');
Route::get('/billing/{tree:slug}/return', [BillingController::class, 'returned'])
    ->middleware('auth')
    ->name('billing.return');
Route::get('/billing/cloudpayments/{payment}', [BillingController::class, 'cloudpayments'])
    ->middleware('auth')
    ->name('billing.cloudpayments');
Route::get('/tree-management/leave', LeaveTreeManagementController::class)
    ->middleware('auth')
    ->name('tree.management.leave');
Route::get('/tree-management/{tree:slug}/preview/{mode}', TreePreviewController::class)
    ->middleware('auth')
    ->where('mode', 'normal|member|guest')
    ->name('tree.preview');
Route::get('/access/pending', function (Request $request) {
    $tree = $request->filled('tree')
        ? FamilyTree::query()->where('slug', $request->string('tree'))->first()
        : null;

    return view('public.pending', compact('tree'));
})->name('access.pending');
Route::get('/invite/{token}', [TreeInvitationController::class, 'show'])->name('tree.invitation');
Route::post('/invite/{token}', [TreeInvitationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('tree.invitation.store');
Route::get('/invitations/{invitation}/qr', InvitationQrController::class)
    ->middleware('auth')
    ->name('tree.invitation.qr');
Route::get('/media/photos/{photo}', [MediaController::class, 'photo'])
    ->middleware(['signed', 'family.media'])
    ->name('media.photo');
Route::get('/media/photos/{photo}/thumbnail', [MediaController::class, 'photoThumbnail'])
    ->middleware(['signed', 'family.media'])
    ->name('media.photo-thumbnail');
Route::get('/media/people/{person}', [MediaController::class, 'person'])
    ->middleware(['signed', 'family.media'])
    ->name('media.person');
Route::get('/media/people/{person}/thumbnail', [MediaController::class, 'personThumbnail'])
    ->middleware(['signed', 'family.media'])
    ->name('media.person-thumbnail');
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
