<?php
declare(strict_types=1);
namespace App\Services\Shopify;
use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Shopify\App\ShopifyApp;
class ShopifyAppService
{
    public function sdk(): ShopifyApp
    {
        abort_unless(config('shopify.api_key') && config('shopify.api_secret'), 503, 'Shopify credentials have not been configured.');
        return new ShopifyApp((string) config('shopify.api_key'), (string) config('shopify.api_secret'));
    }
    public function http(): Client { return new Client(['timeout'=>12,'connect_timeout'=>4]); }
    public function token(Shop $shop): array
    {
        return Cache::lock('shopify-token-'.$shop->id, 25)->block(3, function () use ($shop) {
            $shop->refresh();
            if (!$shop->active() || !$shop->access_token) { throw new ShopifyApiException(false, 'Reconnect the app from Shopify Admin.'); }
            $result = $this->sdk()->refreshTokenExchangedAccessToken($shop->access_token, httpClient: $this->http());
            if (!$result->ok) { throw new ShopifyApiException($result->response->status >= 500); }
            if ($result->accessToken) { $shop->update(['access_token'=>(array) $result->accessToken]); }
            return $shop->access_token;
        });
    }
}
