<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationStatus: string
{
    case PENDING = 'PENDING';
    case EMAIL_QUEUED = 'EMAIL_QUEUED';
    case EMAIL_SENT = 'EMAIL_SENT';
    case DISABLED = 'DISABLED';
    case MANUAL_REVIEW = 'MANUAL_REVIEW';
    case NO_CUSTOMER_EMAIL = 'NO_CUSTOMER_EMAIL';
    case FAILED = 'FAILED';
}
