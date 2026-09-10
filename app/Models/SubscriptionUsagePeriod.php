<?php

namespace App\Models;

class SubscriptionUsagePeriod extends TenantModel
{
    protected $guarded = ['id'];

    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'allowance' => 'integer', 'reserved' => 'integer', 'consumed' => 'integer'];
}
