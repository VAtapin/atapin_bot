<?php

namespace App\Providers;

use App\Models\TelegramUser;
use App\Observers\TelegramUserObserver;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale((string) config('app.locale'));
        TelegramUser::observe(TelegramUserObserver::class);
    }
}
