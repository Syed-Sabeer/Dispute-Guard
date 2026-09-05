<?php

namespace App\Providers;

use App\Services\Billing\BillingServiceInterface;
use App\Services\Billing\ShopifyAppPricingService;
use App\Services\CurrentShop;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentShop::class);
        $this->app->bind(BillingServiceInterface::class, ShopifyAppPricingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        RateLimiter::for('merchant', fn () => Limit::perMinute(90)->by(app(CurrentShop::class)->get()->id));
        RateLimiter::for('test-mail', fn () => Limit::perMinute(5)->by(app(CurrentShop::class)->get()->id));
    }
}
