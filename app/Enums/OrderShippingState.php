<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderShippingState: string
{
    case UNFULFILLED = 'UNFULFILLED';
    case TRACKING_ADDED = 'TRACKING_ADDED';
    case IN_TRANSIT = 'IN_TRANSIT';
    case OUT_FOR_DELIVERY = 'OUT_FOR_DELIVERY';
    case DELIVERED = 'DELIVERED';
    case UNKNOWN = 'UNKNOWN';

    public static function automatic(): array { return array_filter(self::cases(), fn ($state) => $state !== self::UNKNOWN); }
    public function label(): string { return ucwords(strtolower(str_replace('_', ' ', $this->value))); }
}
