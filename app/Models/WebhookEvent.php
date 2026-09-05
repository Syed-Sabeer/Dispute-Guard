<?php

declare(strict_types=1);

namespace App\Models;

class WebhookEvent extends TenantModel
{
    protected $fillable = ['shop_id', 'shop_domain', 'webhook_id', 'topic', 'payload', 'status', 'attempts', 'error_message', 'received_at', 'processed_at'];

    protected $hidden = ['payload'];

    protected $casts = ['payload' => 'encrypted:array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
}
