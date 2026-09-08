<?php

namespace App\Exceptions;

class EmailProviderException extends \RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct(match ($category) {
            'SENDER_NOT_VERIFIED' => 'Merchant sending domain is not verified. Configure your email sender before activating customer emails.',
            'CONFIGURATION' => 'Email delivery is not configured. Please contact app support.',
            'DEFINITE_REJECTION' => 'The email provider rejected this operation. Review the sender and recipient settings.',
            'DELIVERY_OUTCOME_UNKNOWN' => 'Email delivery outcome is uncertain. Automatic retry is suppressed; contact app support.',
            'TRANSIENT_VERIFICATION_FAILURE' => 'Email sender verification could not be refreshed. No customer email was sent. Please try again shortly.',
            default => 'Email verification is temporarily unavailable. Please check again shortly.',
        });
    }
}
