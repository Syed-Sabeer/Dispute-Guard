<?php

declare(strict_types=1);

namespace App\Models;

class EmailTemplate extends TenantModel
{
    protected $fillable = ['shop_id', 'dispute_reason', 'shipping_state', 'enabled', 'subject', 'body', 'is_default_modified'];

    protected $casts = ['enabled' => 'boolean', 'is_default_modified' => 'boolean'];
}
