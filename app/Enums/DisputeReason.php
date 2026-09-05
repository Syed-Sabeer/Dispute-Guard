<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeReason: string
{
    case PRODUCT_NOT_RECEIVED = 'PRODUCT_NOT_RECEIVED';
    case PRODUCT_UNACCEPTABLE = 'PRODUCT_UNACCEPTABLE';
    case FRAUDULENT = 'FRAUDULENT';
    case CREDIT_NOT_PROCESSED = 'CREDIT_NOT_PROCESSED';

    public function label(): string { return ucwords(strtolower(str_replace('_', ' ', $this->value))); }
}
