<?php

namespace App\Services;

final class DeploymentMode
{
    public static function testTools(): bool
    {
        return config('chargeguard.test_tools') ?? app()->environment(['local', 'development', 'testing']);
    }

    public static function privateShop(string $domain): bool
    {
        return config('chargeguard.prelaunch') && in_array($domain, config('chargeguard.prelaunch_shops', []), true);
    }

    public static function permits(string $domain): bool
    {
        return config('chargeguard.billing_enabled') || self::privateShop($domain);
    }
}
