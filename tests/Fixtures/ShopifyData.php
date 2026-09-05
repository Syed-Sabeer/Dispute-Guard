<?php
declare(strict_types=1);
namespace Tests\Fixtures;
trait ShopifyData
{
    protected function shop(array $settings = []): \App\Models\Shop
    {
        $shop = \App\Models\Shop::factory()->create(['shopify_shop_id'=>'gid://shopify/Shop/123']);
        $shop->update(['access_token'=>['accessMode'=>'offline','shop'=>$shop->handle(),'token'=>'offline-test-token','scope'=>'read_orders,read_shopify_payments_disputes','refreshToken'=>null,'refreshTokenExpires'=>null,'expires'=>null,'user'=>null]]);
        $shop->settings()->create($settings+['store_display_name'=>'Demo Store','support_email'=>'support@example.com','reply_to_email'=>'support@example.com','auto_email_enabled'=>true,'test_mode'=>false,'onboarded_at'=>now()]);
        app(\App\Services\Email\DefaultEmailTemplateFactory::class)->seed($shop);
        return $shop;
    }
    protected function token(\App\Models\Shop $shop, array $claims = []): string
    {
        return \Firebase\JWT\JWT::encode($claims+['iss'=>'https://'.$shop->shop_domain.'/admin','dest'=>'https://'.$shop->shop_domain,'aud'=>config('shopify.api_key'),'sub'=>'1','exp'=>time()+60,'nbf'=>time()-1,'iat'=>time(),'jti'=>'test-jti','sid'=>'test-sid'],config('shopify.api_secret'),'HS256');
    }
    protected function merchant(\App\Models\Shop $shop): static
    {
        return $this->withHeaders(['Authorization'=>'Bearer '.$this->token($shop),'X-Requested-With'=>'XMLHttpRequest']);
    }
    protected function order(string $status = 'IN_TRANSIT'): array
    {
        return ['id'=>'gid://shopify/Order/456','name'=>'#1001','email'=>'actual-customer@example.com','createdAt'=>'2026-09-01T10:00:00Z','customer'=>['firstName'=>'Alex'],'displayFulfillmentStatus'=>'FULFILLED',
            'totalPriceSet'=>['shopMoney'=>['amount'=>'49.00','currencyCode'=>'USD']],
            'fulfillments'=>$status === 'UNFULFILLED' ? [] : [['status'=>'SUCCESS','displayStatus'=>$status,'trackingInfo'=>[['number'=>'TRACK-1','url'=>'https://example.com/tracking','company'=>'Carrier']],'events'=>['nodes'=>[['status'=>$status,'happenedAt'=>'2026-09-02T10:00:00Z']],'pageInfo'=>['hasNextPage'=>false]]]],
            'refunds'=>[]];
    }
    protected function disputeData(string $reason = 'PRODUCT_NOT_RECEIVED'): array
    {
        return ['id'=>'gid://shopify/ShopifyPaymentsDispute/789','order'=>['id'=>'gid://shopify/Order/456'],'reasonDetails'=>['reason'=>$reason],'status'=>'NEEDS_RESPONSE','type'=>'CHARGEBACK','amount'=>['amount'=>'49.00','currencyCode'=>'USD'],'initiatedAt'=>'2026-09-03T10:00:00Z','evidenceDueBy'=>'2026-09-15T10:00:00Z'];
    }
    protected function fakeShopify(array $order, ?array $dispute = null): void
    {
        $this->mock(\App\Services\Shopify\ShopifyOrderService::class, fn ($m) => $m->shouldReceive('fetch')->withArgs(fn ($s,$id)=>$id === 'gid://shopify/Order/456')->andReturn($order));
        $this->mock(\App\Services\Shopify\ShopifyDisputeService::class, fn ($m) => $m->shouldReceive('fetch')->andReturn($dispute ?? $this->disputeData()));
    }
    protected function webhook(\App\Models\Shop $shop, string $topic = 'disputes/create', string $id = 'webhook-1', array $payload = ['id'=>789], bool $valid = true)
    {
        $body = json_encode($payload,JSON_THROW_ON_ERROR);
        return $this->call('POST','/webhooks/shopify/'.$topic,[],[],[],[
            'CONTENT_TYPE'=>'application/json','HTTP_X_SHOPIFY_SHOP_DOMAIN'=>$shop->shop_domain,'HTTP_X_SHOPIFY_TOPIC'=>$topic,
            'HTTP_X_SHOPIFY_WEBHOOK_ID'=>$id,'HTTP_X_SHOPIFY_HMAC_SHA256'=>$valid ? base64_encode(hash_hmac('sha256',$body,config('shopify.api_secret'),true)) : 'invalid',
        ],$body);
    }
}
