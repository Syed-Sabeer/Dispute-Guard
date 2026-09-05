<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessDisputeCreated;
use App\Jobs\ProcessDisputeUpdated;
use App\Jobs\SendDisputeCustomerEmail;
use App\Models\Dispute;
use App\Models\EmailLog;
use App\Models\WebhookEvent;
use App\Services\Disputes\DisputeProcessor;
use App\Services\Shopify\ShopifyDisputeService;
use App\Services\Shopify\ShopifyOrderService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SafetyRegressionTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    public function test_dispute_update_webhook_syncs_without_email(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        $this->webhook($shop, 'disputes/update')->assertOk();
        app()->call([new ProcessDisputeUpdated(WebhookEvent::sole()->id), 'handle']);
        $this->assertDatabaseCount('disputes', 1);
        $this->assertDatabaseCount('automation_deliveries', 0);
        Queue::assertNotPushed(SendDisputeCustomerEmail::class);
        $this->assertNull(WebhookEvent::sole()->payload);
    }

    public function test_different_webhook_ids_still_allow_only_one_initial_email(): void
    {
        config(['chargeguard.test_mode' => false]);
        Queue::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        foreach (['one', 'two'] as $id) {
            $this->webhook($shop, id: $id)->assertOk();
            app()->call([new ProcessDisputeCreated(WebhookEvent::where('webhook_id', $id)->sole()->id), 'handle']);
        }
        $this->assertDatabaseCount('automation_deliveries', 1);
        Queue::assertPushed(SendDisputeCustomerEmail::class, 1);
    }

    public function test_unknown_shipping_and_synthetic_source_require_review(): void
    {
        config(['chargeguard.test_mode' => false]);
        Queue::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order('UNKNOWN'));
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame('MANUAL_REVIEW', $d->automation_status);
        $this->fakeShopify($this->order());
        $d = app(DisputeProcessor::class)->process($shop, '790', true, 'synthetic');
        $this->assertSame('MANUAL_REVIEW', $d->automation_status);
        Queue::assertNothingPushed();
    }

    public function test_recipient_changes_and_template_disabling_cancel_sending(): void
    {
        config(['chargeguard.test_mode' => false]);
        Queue::fake();
        Mail::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $order = $this->order();
        $order['email'] = 'changed@example.com';
        $this->fakeShopify($order);
        app()->call([new SendDisputeCustomerEmail($d->automationDeliveries()->sole()->id), 'handle']);
        $this->assertSame('CANCELLED', $d->automationDeliveries()->sole()->status);
        Mail::assertNothingSent();
        $d2 = app(DisputeProcessor::class)->process($shop, '790', true);
        $d2->automationDeliveries()->sole()->template->update(['enabled' => false]);
        app()->call([new SendDisputeCustomerEmail($d2->automationDeliveries()->sole()->id), 'handle']);
        Mail::assertNothingSent();
    }

    public function test_redaction_during_fetch_does_not_repopulate_customer_data(): void
    {
        $shop = $this->shop();
        $d = Dispute::factory()->create(['shop_id' => $shop->id, 'shopify_dispute_id' => '789']);
        $this->mock(ShopifyDisputeService::class, fn ($m) => $m->shouldReceive('fetch')->andReturn($this->disputeData()));
        $this->mock(ShopifyOrderService::class, function ($m) use ($d) {
            $m->shouldReceive('fetch')->andReturnUsing(function () use ($d) {
                $d->update(['redacted_at' => now(), 'tracking_number' => null]);

                return $this->order();
            });
        });
        app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertNull($d->fresh()->tracking_number);
        $this->assertNull($d->fresh()->customer_email_hash);
    }

    public function test_revenue_at_risk_excludes_closed_and_test_data(): void
    {
        $shop = $this->shop();
        foreach ([['NEEDS_RESPONSE', 'shopify', '10.00'], ['UNDER_REVIEW', 'shopify', '20.00'], ['WON', 'shopify', '900.00'], ['NEEDS_RESPONSE', 'demo', '700.00']] as [$status,$source,$amount]) {
            Dispute::factory()->create(['shop_id' => $shop->id, 'status' => $status, 'source' => $source, 'amount' => $amount]);
        }
        $this->merchant($shop)->get('/dashboard')->assertOk()->assertViewHas('risk', fn ($risk) => preg_match('/^30(?:\.0+)?$/D', (string) $risk->sole()->total) === 1);
    }

    public function test_invalid_shop_domain_and_issuer_are_rejected(): void
    {
        $shop = $this->shop();
        $token = $this->token($shop, ['iss' => 'https://evil.example/admin']);
        $this->withToken($token)->getJson('/dashboard')->assertStatus(401);
    }

    public function test_shipment_changes_while_queued_cancel_stale_email(): void
    {
        config(['chargeguard.test_mode' => false]);
        Queue::fake();
        Mail::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame('QUEUED', EmailLog::sole()->status);
        $this->fakeShopify($this->order('DELIVERED'));
        app()->call([new SendDisputeCustomerEmail($d->automationDeliveries()->sole()->id), 'handle']);
        Mail::assertNothingSent();
        $this->assertSame('CANCELLED', EmailLog::sole()->status);
        $this->assertStringContainsString('shipment', $d->fresh()->review_reason);
    }

    public function test_local_demo_is_read_only_and_impossible_in_production(): void
    {
        $this->app->instance('env', 'local');
        config(['chargeguard.demo_mode' => true]);
        $this->seed(DemoDataSeeder::class);
        $this->get('/demo')->assertOk()->assertSee('Local read-only demo');
        $this->postJson('/demo/settings', [])->assertStatus(405);
        $this->app->instance('env', 'production');
        $this->get('/demo')->assertNotFound();
    }
}
