<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(\App\Services\CurrentShop::class);
        $this->app->bind(\App\Services\Billing\BillingServiceInterface::class, \App\Services\Billing\ShopifyAppPricingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) { \Illuminate\Support\Facades\URL::forceScheme('https'); }
        \Illuminate\Support\Facades\RateLimiter::for('merchant', fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute(90)->by(app(\App\Services\CurrentShop::class)->get()->id));
        \Illuminate\Support\Facades\RateLimiter::for('test-mail', fn () => \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by(app(\App\Services\CurrentShop::class)->get()->id));
    }
}
