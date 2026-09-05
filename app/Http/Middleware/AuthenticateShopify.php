<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Services\CurrentShop;
use App\Services\Email\DefaultEmailTemplateFactory;
use App\Services\Shopify\ShopifyAppService;
use App\Services\Shopify\ShopifyRequestVerifier;
use App\Services\Shopify\ShopifyShopService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AuthenticateShopify
{
    public function __construct(private ShopifyAppService $app, private CurrentShop $current) {}

    public function handle(Request $request, Closure $next)
    {
        $result = $this->app->sdk()->verifyAppHomeReq(ShopifyRequestVerifier::request($request), appHomePatchIdTokenPath: '/auth/patch-id-token');
        if (! $result->ok) {
            return ShopifyRequestVerifier::response($result);
        }
        $domain = ShopifyRequestVerifier::domain($result->shop.'.myshopify.com');
        $claims = $result->idToken->claims;
        abort_unless(($claims['dest'] ?? '') === 'https://'.$domain && ($claims['iss'] ?? '') === 'https://'.$domain.'/admin', 401);
        $shop = Shop::firstOrCreate(['shop_domain' => $domain], ['installed_at' => now()]);
        $firstOpen = $shop->wasRecentlyCreated || ! $shop->active();
        $response = Cache::lock('shopify-token-'.$shop->id, 25)->block(3, function () use ($shop, $result) {
            $shop->refresh();
            $uninstalledAt = (string) $shop->uninstalled_at;
            $token = $shop->access_token;
            try {
                $tokenResult = $token && $shop->active()
                    ? $this->app->sdk()->refreshTokenExchangedAccessToken($token, httpClient: $this->app->http())
                    : $this->app->sdk()->exchangeUsingTokenExchange('offline', $result->idToken, $result->invalidTokenResponse, httpClient: $this->app->http());
                if (! $tokenResult->ok && $token && $tokenResult->response->status === 401) {
                    $tokenResult = $this->app->sdk()->exchangeUsingTokenExchange('offline', $result->idToken, $result->invalidTokenResponse, httpClient: $this->app->http());
                }
            } catch (\Throwable) {
                throw new ShopifyApiException(true);
            }
            if (! $tokenResult->ok) {
                return ShopifyRequestVerifier::response($tokenResult);
            }
            $shop->refresh();
            abort_unless((string) $shop->uninstalled_at === $uninstalledAt, 401);
            if ($tokenResult->accessToken) {
                $shop->update(['access_token' => (array) $tokenResult->accessToken, 'status' => 'ACTIVE', 'uninstalled_at' => null, 'installed_at' => $shop->active() ? $shop->installed_at : now()]);
            }

            return null;
        });
        if ($response) {
            return $response;
        }
        $shop->settings()->firstOrCreate([], ['store_display_name' => $shop->store_name]);
        app(DefaultEmailTemplateFactory::class)->seed($shop);
        if (! $shop->shopify_shop_id) {
            app(ShopifyShopService::class)->sync($shop);
        }
        $this->current->set($shop);
        if ($firstOpen && $request->path() === 'dashboard') {
            return ShopifyRequestVerifier::response($this->app->sdk()->appHomeRedirect(
                ShopifyRequestVerifier::request($request), '/onboarding', $shop->handle()
            ));
        }
        $request->attributes->set('shopify_verified', $result);
        $response = $next($request);
        foreach ((array) $result->response->headers as $key => $value) {
            $response->headers->set($key, $value);
        }
        $response->headers->set('Content-Security-Policy', 'frame-ancestors https://'.$domain.' https://admin.shopify.com;');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
