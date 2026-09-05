<?php

declare(strict_types=1);

namespace App\Enums;

enum DisputeStatus: string
{
    case NEEDS_RESPONSE = 'NEEDS_RESPONSE';
    case UNDER_REVIEW = 'UNDER_REVIEW';
    case WON = 'WON';
    case LOST = 'LOST';
    case ACCEPTED = 'ACCEPTED';
    case PREVENTED = 'PREVENTED';

    public static function open(): array { return [self::NEEDS_RESPONSE->value, self::UNDER_REVIEW->value]; }
}
