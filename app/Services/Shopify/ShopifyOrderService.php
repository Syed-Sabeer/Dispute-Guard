<?php
declare(strict_types=1);
namespace App\Services\Shopify;
use App\Models\Shop;
class ShopifyOrderService
{
    public function __construct(private ShopifyGraphQLClient $client) {}
    public function fetch(Shop $shop, string $id): ?array
    {
        if (!preg_match('~^gid://shopify/Order/[0-9]+$~D', $id)) { return null; }
        return $this->client->query($shop, 'order', ['id'=>$id])['order'] ?? null;
    }
}
