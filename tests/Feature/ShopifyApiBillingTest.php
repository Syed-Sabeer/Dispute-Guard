<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Services\Billing\ShopifyAppPricingService;
use App\Services\Shopify\ShopifyAppService;
use App\Services\Shopify\ShopifyGraphQLClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyApiBillingTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    private function client(Response $response): ShopifyGraphQLClient
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
        $app = new class($client) extends ShopifyAppService
        {
            public function __construct(private Client $client) {}

            public function http(): Client
            {
                return $this->client;
            }
        };

        return new ShopifyGraphQLClient($app);
    }

    public static function errors(): array
    {
        return [[429, [], true], [500, [], true], [403, [], false], [401, [], false], [200, ['errors' => [['extensions' => ['code' => 'THROTTLED']]]], true], [200, ['errors' => [['message' => 'Invalid field']]], false], [200, ['data' => ['action' => ['userErrors' => [['message' => 'Invalid input']]]]], false]];
    }

    #[DataProvider('errors')]
    public function test_api_errors_are_sanitized_and_classified(int $status, array $body, bool $transient): void
    {
        $shop = $this->shop();
        try {
            $this->client(new Response($status, [], json_encode($body)))->query($shop, 'shop');
            $this->fail('API failure was ignored.');
        } catch (ShopifyApiException $e) {
            $this->assertSame($transient, $e->transient);
            $this->assertStringNotContainsString('offline-test-token', $e->getMessage());
        }
    }

    public function test_valid_graphql_data_is_returned_through_official_sdk(): void
    {
        $shop = $this->shop();
        $data = $this->client(new Response(200, [], json_encode(['data' => ['shop' => ['id' => 'gid://shopify/Shop/123']]])))->query($shop, 'shop');
        $this->assertSame('gid://shopify/Shop/123', $data['shop']['id']);
    }

    public function test_billing_uses_partner_api_and_server_side_handles(): void
    {
        $shop = $this->shop();
        config(['chargeguard.billing_enforced' => true, 'shopify.partner_id' => '123', 'shopify.partner_token' => 'partner-test-token', 'shopify.app_id' => 'gid://shopify/App/456', 'chargeguard.billing_items.starter' => 'starter-base']);
        Http::fake(['partners.shopify.com/*' => Http::sequence()
            ->push(['data' => ['activeSubscription' => ['items' => [['handle' => 'starter-base', 'price' => ['active' => true]]]]]])
            ->push(['data' => ['activeSubscription' => null]])
            ->push(['errors' => [['message' => 'throttled']]])]);
        $service = app(ShopifyAppPricingService::class);
        $this->assertTrue($service->entitled($shop));
        $this->assertSame('starter', $shop->fresh()->plan_handle);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'partners.shopify.com/123/api/2026-07/graphql.json') && $r['variables']['shopId'] === $shop->shopify_shop_id);
        $this->assertFalse($service->entitled($shop));
        $this->assertFalse($service->entitled($shop));
    }

    public function test_production_cannot_use_development_billing_bypass(): void
    {
        $shop = $this->shop();
        $this->app->instance('env', 'production');
        config(['chargeguard.billing_enforced' => false, 'shopify.partner_token' => null]);
        $this->assertFalse(app(ShopifyAppPricingService::class)->entitled($shop));
    }

    public static function entryPaths(): array
    {
        return [['/dashboard'], ['/']];
    }

    public static function refreshRecovery(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('refreshRecovery')]
    public function test_failed_refresh_exchanges_verified_session_once(bool $exchangeSucceeds): void
    {
        $shop = $this->shop();
        $savedToken = array_merge($shop->access_token, [
            'expires' => now()->subMinute()->toIso8601String(),
            'refreshToken' => 'stale-refresh-token',
            'refreshTokenExpires' => now()->addDay()->toIso8601String(),
        ]);
        $shop->update(['access_token' => $savedToken]);
        $mock = new MockHandler([
            new Response(400, [], json_encode(['error' => 'invalid_request'])),
            $exchangeSucceeds
                ? new Response(200, [], json_encode(['access_token' => 'replacement-token', 'scope' => 'read_orders,read_shopify_payments_disputes', 'expires_in' => 3600, 'refresh_token' => 'replacement-refresh', 'refresh_token_expires_in' => 7776000]))
                : new Response(400, [], json_encode(['error' => 'invalid_client'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $this->app->instance(ShopifyAppService::class, new class($client) extends ShopifyAppService
        {
            public function __construct(private Client $client) {}

            public function http(): Client
            {
                return $this->client;
            }
        });

        $response = $this->get('/?'.http_build_query(['shop' => $shop->shop_domain, 'id_token' => $this->token($shop)]));
        $this->assertSame(0, $mock->count());
        if ($exchangeSucceeds) {
            $response->assertOk()->assertSee('Open disputes');
            $this->assertSame('replacement-token', $shop->fresh()->access_token['token']);
        } else {
            $response->assertStatus(500)->assertDontSee('Open disputes');
            $this->assertSame($savedToken, $shop->fresh()->access_token);
        }
        $response->assertDontSee('stale-refresh-token')->assertDontSee('replacement-token');
    }

    #[DataProvider('entryPaths')]
    public function test_token_exchange_initializes_shop_and_default_templates(string $path): void
    {
        $domain = 'new-install.myshopify.com';
        $shop = new Shop(['shop_domain' => $domain]);
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'issued-offline-token', 'scope' => 'read_orders,read_shopify_payments_disputes', 'expires_in' => 3600, 'refresh_token' => 'issued-refresh-token', 'refresh_token_expires_in' => 7776000])),
            new Response(200, [], json_encode(['data' => ['shop' => ['id' => 'gid://shopify/Shop/555', 'name' => 'New Store', 'email' => 'owner@example.com', 'ianaTimezone' => 'UTC', 'currencyCode' => 'USD']]])),
        ]))]);
        $service = new class($client) extends ShopifyAppService
        {
            public function __construct(private Client $client) {}

            public function http(): Client
            {
                return $this->client;
            }
        };
        $this->app->instance(ShopifyAppService::class, $service);
        $this->merchant($shop)->get($path)->assertRedirect('/onboarding')->assertDontSee('issued-offline-token');
        $this->get('/onboarding')->assertOk();
        $installed = Shop::where('shop_domain', $domain)->sole();
        $this->assertSame(20, $installed->emailTemplates()->count());
        $this->assertFalse($installed->settings->auto_email_enabled);
        $this->assertSame('issued-offline-token', $installed->access_token['token']);
        $this->assertSame('offline', $installed->access_token['accessMode']);
    }
}
