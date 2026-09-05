<?php
declare(strict_types=1);
namespace App\Services\Shopify;
use App\Models\Shop;
class ShopifyShopService
{
    public function __construct(private ShopifyGraphQLClient $client) {}
    public function sync(Shop $shop): void
    {
        $data = $this->client->query($shop, 'shop')['shop'] ?? null;
        if (!$data) { throw new \App\Exceptions\ShopifyApiException(); }
        $shop->update(['shopify_shop_id'=>$data['id'],'store_name'=>$data['name'],'email'=>$data['email'],'timezone'=>$data['ianaTimezone'],'currency'=>$data['currencyCode']]);
    }
}
