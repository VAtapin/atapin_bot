<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\TreePanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    TreePanelProvider::class,
];
