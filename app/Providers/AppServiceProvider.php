<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\ProductHierarchy;
use App\Observers\ClientObserver;
use App\Observers\ProductHierarchyObserver;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
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
        Client::observe(ClientObserver::class);
        ProductHierarchy::observe(ProductHierarchyObserver::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            fn (): string => Blade::render('<x-notifications.toast />'),
        );
    }
}
