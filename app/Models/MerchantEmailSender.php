<?php

namespace App\Models;

class MerchantEmailSender extends TenantModel
{
    protected $guarded = ['id'];

    protected $casts = ['dkim_verified' => 'boolean', 'return_path_verified' => 'boolean', 'ownership_verified' => 'boolean', 'verified_at' => 'datetime', 'last_checked_at' => 'datetime'];

    public function sendingDomain()
    {
        return $this->belongsTo(EmailSendingDomain::class, 'email_sending_domain_id');
    }
}
