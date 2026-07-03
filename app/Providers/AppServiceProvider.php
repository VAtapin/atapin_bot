<?php

namespace App\Providers;

use App\Models\ChangeLog;
use App\Models\CmsPage;
use App\Models\Congratulation;
use App\Models\DataIssue;
use App\Models\FamilyEvent;
use App\Models\ParentChild;
use App\Models\Partnership;
use App\Models\Person;
use App\Models\PersonPhoto;
use App\Models\PhotoAlbum;
use App\Models\TelegramUser;
use App\Models\TreeMembership;
use App\Observers\ChangeLogObserver;
use App\Observers\CmsPageObserver;
use App\Observers\DataIssueObserver;
use App\Observers\FamilyCacheObserver;
use App\Observers\PersonObserver;
use App\Observers\PersonPhotoObserver;
use App\Observers\TelegramUserObserver;
use App\Observers\TreeMembershipObserver;
use App\Services\PlatformMailConfigurator;
use App\Support\CurrentTree;
use App\Support\FormHelp;
use Carbon\Carbon;
use Filament\Forms\Components\Field;
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
        Field::configureUsing(function (Field $field): void {
            if ($help = FormHelp::for($field->getName())) {
                $field->helperText($help);
            }
        });
        Carbon::setLocale((string) config('app.locale'));
        TelegramUser::observe(TelegramUserObserver::class);
        TreeMembership::observe(TreeMembershipObserver::class);
        DataIssue::observe(DataIssueObserver::class);
        ChangeLog::observe(ChangeLogObserver::class);
        CmsPage::observe(CmsPageObserver::class);
        Person::observe(PersonObserver::class);
        PersonPhoto::observe(PersonPhotoObserver::class);
        foreach ([
            Person::class,
            PersonPhoto::class,
            ParentChild::class,
            Partnership::class,
            FamilyEvent::class,
            PhotoAlbum::class,
            Congratulation::class,
            TreeMembership::class,
        ] as $familyModel) {
            $familyModel::observe(FamilyCacheObserver::class);
        }
        app(PlatformMailConfigurator::class)->apply();
    }
}
