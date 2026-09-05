<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Billing\BillingServiceInterface;
use App\Services\CurrentShop;
use Closure;

class EnsureActiveSubscription
{
    public function handle($request, Closure $next)
    {
        if ($request->boolean('auto_email_enabled')) {
            abort_unless(app(BillingServiceInterface::class)->entitled(app(CurrentShop::class)->get()), 402, 'Choose a plan or verify your subscription on the Billing page.');
        }

        return $next($request);
    }
}
