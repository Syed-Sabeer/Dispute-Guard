<?php

namespace App\Providers;

use App\Services\Billing\BillingServiceInterface;
use App\Services\Billing\ShopifyAppPricingService;
use App\Services\CurrentShop;
use App\Services\Email\EmailProviderInterface;
use App\Services\Email\PostmarkEmailProvider;
use App\Services\Email\PostmarkTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Mail;
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
        $this->app->bind(EmailProviderInterface::class, PostmarkEmailProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('postmark', fn () => new PostmarkTransport(app(EmailProviderInterface::class)));
        RateLimiter::for('sender-domain', fn () => Limit::perMinute(3)->by(app(CurrentShop::class)->get()->id));
        RateLimiter::for('sender-create', fn () => Limit::perDay(10)->by(app(CurrentShop::class)->get()->id));
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
        RateLimiter::for('merchant', fn () => Limit::perMinute(90)->by(app(CurrentShop::class)->get()->id));
        RateLimiter::for('test-mail', fn () => Limit::perMinute(5)->by(app(CurrentShop::class)->get()->id));
    }
}
