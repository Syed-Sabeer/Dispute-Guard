<?php

declare(strict_types=1);

namespace App\Services\Shopify;

use App\Models\Shop;

class ShopifyDisputeService
{
    public function __construct(private ShopifyGraphQLClient $client) {}

    public function fetch(Shop $shop, string $id): ?array
    {
        $gid = str_starts_with($id, 'gid://shopify/ShopifyPaymentsDispute/') ? $id : 'gid://shopify/ShopifyPaymentsDispute/'.$id;
        if (! preg_match('~^gid://shopify/ShopifyPaymentsDispute/[0-9]+$~D', $gid)) {
            return null;
        }

        return $this->client->query($shop, 'dispute', ['id' => $gid])['dispute'] ?? null;
    }
}
