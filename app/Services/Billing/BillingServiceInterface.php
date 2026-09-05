<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Shop;

interface BillingServiceInterface
{
    public function entitled(Shop $shop): bool;

    public function manageUrl(Shop $shop): ?string;
}
