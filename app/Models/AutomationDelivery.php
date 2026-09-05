<?php

declare(strict_types=1);

namespace App\Models;

class AutomationDelivery extends TenantModel
{
    protected $fillable = ['shop_id', 'dispute_id', 'email_template_id', 'shipping_state', 'dispute_reason', 'status', 'recipient_hash', 'sent_at', 'claimed_at', 'failure_reason'];

    protected $casts = ['sent_at' => 'datetime', 'claimed_at' => 'datetime'];

    public function dispute()
    {
        return $this->belongsTo(Dispute::class);
    }

    public function template()
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function emailLog()
    {
        return $this->hasOne(EmailLog::class);
    }
}
