<?php

namespace App\Models;

class MerchantEmailSender extends TenantModel
{
    protected $guarded = ['id'];

    protected $hidden = ['verification_token_hash'];

    protected $casts = ['provider_signature_id' => 'integer', 'provider_confirmed_at' => 'datetime', 'verification_expires_at' => 'datetime', 'verification_sent_at' => 'datetime', 'dkim_verified' => 'boolean', 'return_path_verified' => 'boolean', 'ownership_verified' => 'boolean', 'verified_at' => 'datetime', 'last_checked_at' => 'datetime', 'verification_refresh_failed_at' => 'datetime'];

    public function sendingDomain()
    {
        return $this->belongsTo(EmailSendingDomain::class, 'email_sending_domain_id');
    }
}
