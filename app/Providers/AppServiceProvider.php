<?php

namespace App\Providers;

use App\Models\ChangeLog;
use App\Models\DataIssue;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Observers\ChangeLogObserver;
use App\Observers\DataIssueObserver;
use App\Observers\TelegramUserObserver;
use App\Observers\TreeMembershipObserver;
use App\Support\CurrentTree;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTree::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Carbon::setLocale((string) config('app.locale'));
        TelegramUser::observe(TelegramUserObserver::class);
        TreeMembership::observe(TreeMembershipObserver::class);
        DataIssue::observe(DataIssueObserver::class);
        ChangeLog::observe(ChangeLogObserver::class);
    }
}
