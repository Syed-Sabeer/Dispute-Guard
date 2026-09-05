<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\CurrentShop;
use Closure;

class LocalDemo
{
    public function handle($request, Closure $next)
    {
        abort_unless(app()->environment('local') && config('chargeguard.demo_mode') && in_array($request->ip(), ['127.0.0.1', '::1'], true), 404);
        $shop = Shop::where('shop_domain', 'chargeguard-demo.myshopify.com')->firstOrFail();
        app(CurrentShop::class)->set($shop);
        $request->attributes->set('local_demo', true);

        return $next($request);
    }
}
