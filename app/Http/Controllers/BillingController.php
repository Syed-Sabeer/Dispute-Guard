<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Billing\BillingServiceInterface;

class BillingController extends MerchantController
{
    public function __invoke(BillingServiceInterface $billing)
    {
        abort_unless(config('chargeguard.billing_enabled'), 404);
        $shop = $this->shop();
        $entitled = $billing->entitled($shop);

        return $this->page('billing.index', ['entitled' => $entitled, 'manageUrl' => $billing->manageUrl($shop)]);
    }
}
