<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Shop;
use App\Services\DeploymentMode;
use Illuminate\Support\Facades\Http;

class ShopifyAppPricingService implements BillingServiceInterface
{
    public function entitled(Shop $shop): bool
    {
        if (! $shop->active()) {
            return false;
        }
        if (! config('chargeguard.billing_enabled')) {
            return DeploymentMode::privateShop($shop->shop_domain);
        }
        if (app()->environment(['local', 'testing']) && ! config('chargeguard.billing_enforced')) {
            return true;
        }
        $partner = config('shopify.partner_id');
        $token = config('shopify.partner_token');
        $app = config('shopify.app_id');
        $version = config('shopify.partner_api_version');
        if (! $partner || ! $token || ! $app || ! $shop->shopify_shop_id || ! ctype_digit((string) $partner) || ! preg_match('/^20\d{2}-\d{2}$/D', $version)) {
            return false;
        }
        try {
            $response = Http::timeout(10)->connectTimeout(3)->withHeaders(['X-Shopify-Access-Token' => $token])->post(
                "https://partners.shopify.com/{$partner}/api/{$version}/graphql.json",
                ['query' => file_get_contents(resource_path('graphql/active-subscription.graphql')), 'variables' => ['appId' => $app, 'shopId' => $shop->shopify_shop_id]]
            );
            if (! $response->successful() || $response->json('errors') || ! is_array($response->json('data'))) {
                throw new \RuntimeException;
            }
            $sub = $response->json('data.activeSubscription');
            $items = collect($sub['items'] ?? [])->filter(fn ($i) => data_get($i, 'price.active') === true);
            $matched = $items->first(fn ($i) => in_array($i['handle'], array_values(config('chargeguard.billing_items')), true));
            $active = $sub !== null && $matched !== null;
            $plan = $matched ? array_search($matched['handle'], config('chargeguard.billing_items'), true) : null;
            $shop->update(['billing_status' => $active ? 'ACTIVE' : 'INACTIVE', 'plan_handle' => $plan ? config('chargeguard.billing.'.$plan) : null, 'billing_checked_at' => now()]);

            return $active;
        } catch (\Throwable) {
            $shop->update(['billing_status' => 'UNVERIFIED', 'billing_checked_at' => now()]);

            return false;
        }
    }

    public function manageUrl(Shop $shop): ?string
    {
        $handle = config('shopify.app_handle');
        if (! $handle || ! preg_match('/^[a-z0-9-]+$/D', $handle)) {
            return null;
        }

        return 'https://admin.shopify.com/store/'.$shop->handle().'/charges/'.$handle.'/pricing_plans';
    }
}
