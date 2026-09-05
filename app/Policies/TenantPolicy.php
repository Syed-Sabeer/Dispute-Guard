<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Shop;
use App\Models\TenantModel;

class TenantPolicy
{
    public function access(Shop $shop, TenantModel $record): bool
    {
        return $shop->active() && (int) $record->shop_id === (int) $shop->id;
    }
}
