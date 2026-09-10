<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Billing\BillingServiceInterface;
use App\Services\Billing\UsageQuota;

class BillingController extends MerchantController
{
    public function __invoke(BillingServiceInterface $billing)
    {
        abort_unless(config('chargeguard.billing_enabled'), 404);
        $shop = $this->shop();
        $entitled = $billing->entitled($shop);

        return $this->page('billing.index', ['entitled' => $entitled, 'manageUrl' => $billing->manageUrl($shop), 'usage' => app(UsageQuota::class)->summary($shop)]);
    }

    public function plans(BillingServiceInterface $billing)
    {
        if (config('chargeguard.billing_enabled')) {
            $billing->entitled($this->shop());
        }

        return $this->page('billing.pricing', ['manageUrl' => config('chargeguard.billing_enabled') ? $billing->manageUrl($this->shop()) : null,
            'usage' => app(UsageQuota::class)->summary($this->shop())]);
    }
}
