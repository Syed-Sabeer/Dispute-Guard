<?php

namespace App\Exceptions;

class EmailProviderException extends \RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct(match ($category) {
            'QUOTA_UNAVAILABLE' => 'The reserved follow-up is no longer eligible under the current billing period or safety checks. No email was sent; manual review required.',
            'SENDER_NOT_VERIFIED' => 'Verify your sender email before automatic customer follow-ups can be sent.',
            'CONFIGURATION' => 'Email delivery is not configured. Please contact app support.',
            'DEFINITE_REJECTION' => 'The email provider rejected this operation. Review the sender and recipient settings.',
            'DELIVERY_OUTCOME_UNKNOWN' => 'Email delivery outcome is uncertain. Automatic retry is suppressed; contact app support.',
            'TRANSIENT_VERIFICATION_FAILURE' => 'Email sender verification could not be refreshed. No customer email was sent. Please try again shortly.',
            default => 'Email verification is temporarily unavailable. Please check again shortly.',
        });
    }
}
