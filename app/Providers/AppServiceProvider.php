<?php

namespace App\Providers;

use App\Models\ChangeLog;
use App\Models\CmsPage;
use App\Models\DataIssue;
use App\Models\Person;
use App\Models\PlatformSetting;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Observers\ChangeLogObserver;
use App\Observers\CmsPageObserver;
use App\Observers\DataIssueObserver;
use App\Observers\PersonObserver;
use App\Observers\TelegramUserObserver;
use App\Observers\TreeMembershipObserver;
use App\Support\CurrentTree;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        CmsPage::observe(CmsPageObserver::class);
        Person::observe(PersonObserver::class);
        $this->applyRuntimeMailSettings();
    }

    private function applyRuntimeMailSettings(): void
    {
        try {
            if (! Schema::hasTable('platform_settings') || ! PlatformSetting::value('smtp_enabled', false)) {
                return;
            }

            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => PlatformSetting::value('smtp_host'),
                'mail.mailers.smtp.port' => PlatformSetting::value('smtp_port', 587),
                'mail.mailers.smtp.scheme' => PlatformSetting::value('smtp_encryption', 'tls') === 'ssl'
                    ? 'smtps'
                    : 'smtp',
                'mail.mailers.smtp.username' => PlatformSetting::value('smtp_username'),
                'mail.mailers.smtp.password' => PlatformSetting::value('smtp_password'),
                'mail.mailers.smtp.timeout' => PlatformSetting::value('smtp_timeout', 15),
                'mail.from.address' => PlatformSetting::value('smtp_from_address', config('mail.from.address')),
                'mail.from.name' => PlatformSetting::value('smtp_from_name', config('mail.from.name')),
            ]);
        } catch (Throwable) {
            // Миграции и первая установка могут выполняться до создания таблицы настроек.
        }
    }
}
