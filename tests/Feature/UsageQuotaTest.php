<?php

namespace Tests\Feature;

use App\Jobs\ProcessPrivacyWebhook;
use App\Jobs\SendDisputeCustomerEmail;
use App\Models\AutomationDelivery;
use App\Models\Dispute;
use App\Models\SubscriptionUsagePeriod;
use App\Models\WebhookEvent;
use App\Services\Billing\UsageQuota;
use App\Services\Disputes\DisputeProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UsageQuotaTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    private $realQueue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->realQueue = app('queue');
        config(['chargeguard.test_mode' => false]);
        Queue::fake();
        Mail::fake();
        $this->fakeShopify($this->order());
    }

    private function process($shop, string $id = '789')
    {
        return app(DisputeProcessor::class)->process($shop, $id, true);
    }

    public function test_boundary_is_per_shop_preserves_preference_and_does_not_replay(): void
    {
        $a = $this->shop();
        $b = $this->shop();
        app(UsageQuota::class)->summary($a);
        SubscriptionUsagePeriod::where('shop_id', $a->id)->update(['consumed' => 999]);
        $first = $this->process($a);
        $blocked = $this->process($a, '790');
        $this->assertSame('EMAIL_QUEUED', $first->automation_status);
        $this->assertSame('MANUAL_REVIEW', $blocked->automation_status);
        $this->assertSame(UsageQuota::EXHAUSTED, $blocked->review_reason);
        $this->assertTrue($a->settings()->first()->auto_email_enabled);
        $this->assertSame('EMAIL_QUEUED', $this->process($b)->automation_status);
        $this->assertSame(0, app(UsageQuota::class)->summary($a)['remaining']);
        $this->assertSame(999, app(UsageQuota::class)->summary($b)['remaining']);
        $this->merchant($a)->get('/')->assertOk()->assertSee('1,000')->assertSee('Upgrade plan')->assertSee('Quota exhausted');
        $oldEnd = $a->billing_period_end;
        $this->travelTo($oldEnd->copy()->addSecond());
        $a->update(['billing_period_start' => $oldEnd, 'billing_period_end' => $oldEnd->copy()->addDays(30)]);
        $this->assertSame(1000, app(UsageQuota::class)->summary($a)['remaining']);
        $this->assertSame('MANUAL_REVIEW', $this->process($a, '790')->automation_status);
        $this->assertSame(2, AutomationDelivery::count());
    }

    public function test_sent_consumes_exactly_once(): void
    {
        $shop = $this->shop();
        $delivery = $this->process($shop)->automationDeliveries()->sole();
        $this->assertSame(1, app(UsageQuota::class)->summary($shop)['reserved']);
        $job = new SendDisputeCustomerEmail($delivery->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame('CONSUMED', $delivery->fresh()->quota_status);
        $this->assertSame(1, app(UsageQuota::class)->summary($shop)['used']);
        $this->assertSame(0, app(UsageQuota::class)->summary($shop)['reserved']);
        Mail::assertSentCount(1);
    }

    public function test_sender_change_releases_reservation_and_does_not_send(): void
    {
        $shop = $this->shop();
        $delivery = $this->process($shop)->automationDeliveries()->sole();
        $shop->settings()->update(['store_display_name' => 'Changed']);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('RELEASED', $delivery->fresh()->quota_status);
        $this->assertSame(1000, app(UsageQuota::class)->summary($shop)['remaining']);
        Mail::assertNothingSent();
    }

    public function test_upgrade_and_downgrade_do_not_reset_consumption(): void
    {
        $shop = $this->shop();
        app(UsageQuota::class)->summary($shop);
        SubscriptionUsagePeriod::where('shop_id', $shop->id)->update(['consumed' => 1000]);
        $shop->update(['quota_plan' => 'growth']);
        $this->assertSame(2000, app(UsageQuota::class)->summary($shop)['remaining']);
        $delivery = $this->process($shop)->automationDeliveries()->sole();
        $shop->update(['quota_plan' => 'starter']);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        $this->assertSame('RELEASED', $delivery->fresh()->quota_status);
        $this->assertSame(1000, app(UsageQuota::class)->summary($shop)['used']);
        $this->assertSame(1, SubscriptionUsagePeriod::count());
        Mail::assertNothingSent();
    }

    public function test_old_period_queue_is_released_not_transferred_to_new_period(): void
    {
        $shop = $this->shop();
        $delivery = $this->process($shop)->automationDeliveries()->sole();
        $end = $shop->billing_period_end;
        $this->travelTo($end->copy()->addSecond());
        $shop->update(['billing_period_start' => $end, 'billing_period_end' => $end->copy()->addDays(30)]);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('RELEASED', $delivery->fresh()->quota_status);
        $this->assertSame(1000, app(UsageQuota::class)->summary($shop)['remaining']);
        Mail::assertNothingSent();
    }

    public function test_missing_period_blocks_queue_without_a_calendar_fallback(): void
    {
        $shop = $this->shop();
        $shop->update(['billing_period_start' => null, 'billing_period_end' => null]);
        $this->assertSame(UsageQuota::UNAVAILABLE, $this->process($shop)->review_reason);
        $this->assertSame(0, AutomationDelivery::count());
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
    }

    public function test_interrupted_transport_consumes_but_interrupted_preparation_releases(): void
    {
        $shop = $this->shop();
        $sent = $this->process($shop)->automationDeliveries()->sole();
        $unsent = $this->process($shop, '790')->automationDeliveries()->sole();
        $sent->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(6), 'transport_started_at' => now()->subMinutes(6)]);
        $unsent->update(['status' => 'SENDING', 'claimed_at' => now()->subMinutes(6)]);
        $this->artisan('chargeguard:maintain')->assertExitCode(0);
        $this->artisan('chargeguard:maintain')->assertExitCode(0);
        $this->assertSame('UNKNOWN', $sent->fresh()->status);
        $this->assertSame('CONSUMED', $sent->fresh()->quota_status);
        $this->assertSame('CANCELLED', $unsent->fresh()->status);
        $this->assertSame('RELEASED', $unsent->fresh()->quota_status);
        $this->assertSame(1, app(UsageQuota::class)->summary($shop)['used']);
    }

    public function test_cross_shop_delivery_cannot_release_another_shops_reservation(): void
    {
        $a = $this->shop();
        $b = $this->shop();
        $delivery = $this->process($a)->automationDeliveries()->sole();
        $delivery->shop_id = $b->id; // Untrusted in-memory tenant cannot target A's period.
        app(UsageQuota::class)->settle($delivery, false);
        $this->assertSame('RESERVED', $delivery->fresh()->quota_status);
        $this->assertSame(1, app(UsageQuota::class)->summary($a)['reserved']);
    }

    public function test_all_plan_caps_and_prices_are_applied_per_period(): void
    {
        foreach (['starter' => [59, 1000], 'growth' => [99, 3000], 'pro' => [149, 10000]] as $plan => [$price, $limit]) {
            $shop = $this->shop();
            $shop->update(['quota_plan' => $plan]);
            $this->assertSame($price, config('quotas.plans.'.$plan.'.price'));
            $this->assertSame($limit, app(UsageQuota::class)->summary($shop)['allowance']);
            SubscriptionUsagePeriod::where('shop_id', $shop->id)->update(['consumed' => $limit]);
            $this->assertSame(UsageQuota::EXHAUSTED, $this->process($shop)->review_reason);
        }
    }

    public function test_privacy_releases_unstarted_but_retains_inflight_consumption(): void
    {
        $shop = $this->shop();
        $queued = $this->process($shop)->automationDeliveries()->sole();
        $inflight = $this->process($shop, '790')->automationDeliveries()->sole();
        $inflight->update(['status' => 'SENDING', 'transport_started_at' => now()]);
        $this->webhook($shop, 'customers/redact', 'quota-privacy', ['orders_to_redact' => [456]])->assertOk();
        app()->call([new ProcessPrivacyWebhook(WebhookEvent::sole()->id), 'handle']);
        $this->assertSame('RELEASED', $queued->fresh()->quota_status);
        $this->assertSame('CONSUMED', $inflight->fresh()->quota_status);
        $this->assertSame(1, app(UsageQuota::class)->summary($shop)['used']);
        $this->assertNull($queued->fresh()->recipient_hash);
    }

    public function test_uninstall_releases_queued_reservation(): void
    {
        $shop = $this->shop();
        $delivery = $this->process($shop)->automationDeliveries()->sole();
        $this->webhook($shop, 'app/uninstalled')->assertOk();
        $this->assertSame('RELEASED', $delivery->fresh()->quota_status);
        $this->assertSame(0, SubscriptionUsagePeriod::sole()->reserved);
        $this->assertFalse($shop->fresh()->active());
    }

    public function test_private_assignment_cannot_reset_an_ongoing_period_or_enable_sending(): void
    {
        $shop = $this->shop(['auto_email_enabled' => false]);
        config(['chargeguard.billing_enabled' => false, 'chargeguard.test_mode' => true,
            'chargeguard.prelaunch' => true, 'chargeguard.prelaunch_shops' => [$shop->shop_domain]]);
        $args = ['shop' => $shop->shop_domain, 'plan' => 'growth', 'start' => $shop->billing_period_start->toIso8601String(), 'end' => $shop->billing_period_end->toIso8601String()];
        $this->artisan('chargeguard:quota-period', $args)->assertExitCode(0);
        $this->assertSame('growth', $shop->fresh()->quota_plan);
        $this->assertFalse($shop->settings()->first()->auto_email_enabled);
        $args['start'] = now()->subDay()->toIso8601String();
        $this->artisan('chargeguard:quota-period', $args)->assertExitCode(1);
        $this->assertTrue(config('chargeguard.test_mode'));
        $this->assertFalse(config('chargeguard.billing_enabled'));
    }

    public function test_enqueue_rollback_returns_reserved_capacity(): void
    {
        $shop = $this->shop();
        app(UsageQuota::class)->summary($shop);
        try {
            DB::transaction(function () use ($shop) {
                $this->assertNotNull(app(UsageQuota::class)->reserve($shop));
                throw new \RuntimeException('Simulated queue write failure');
            });
        } catch (\RuntimeException) {
            $this->assertSame(1000, app(UsageQuota::class)->summary($shop)['remaining']);
        }
        $this->assertSame(0, AutomationDelivery::count());
    }

    public function test_downgrade_accounts_for_later_inflight_reservations(): void
    {
        $shop = $this->shop();
        $shop->update(['quota_plan' => 'growth']);
        app(UsageQuota::class)->summary($shop);
        SubscriptionUsagePeriod::where('shop_id', $shop->id)->update(['consumed' => 999]);
        $earlier = $this->process($shop)->automationDeliveries()->sole();
        $later = $this->process($shop, '790')->automationDeliveries()->sole();
        $later->update(['status' => 'SENDING', 'transport_started_at' => now()]);
        $shop->update(['quota_plan' => 'starter']);
        app()->call([new SendDisputeCustomerEmail($earlier->id), 'handle']);
        $this->assertSame('RELEASED', $earlier->fresh()->quota_status);
        $this->assertSame(0, app(UsageQuota::class)->summary($shop)['remaining']);
        Mail::assertNothingSent();
    }

    public function test_rollout_counts_legacy_sends_once_without_counting_unsent_queue(): void
    {
        $shop = $this->shop();
        foreach (['SENT', 'UNKNOWN', 'QUEUED'] as $status) {
            $dispute = Dispute::factory()->create(['shop_id' => $shop->id]);
            AutomationDelivery::create(['shop_id' => $shop->id, 'dispute_id' => $dispute->id, 'status' => $status,
                'shipping_state' => 'IN_TRANSIT', 'dispute_reason' => 'PRODUCT_NOT_RECEIVED']);
        }
        $this->assertSame(2, app(UsageQuota::class)->summary($shop)['used']);
        $this->assertSame(2, app(UsageQuota::class)->summary($shop)['used']);
        $this->assertSame(2, AutomationDelivery::where('quota_status', 'CONSUMED')->count());
    }

    public function test_database_job_is_inserted_in_the_reservation_transaction(): void
    {
        Queue::swap($this->realQueue);
        $shop = $this->shop();
        $this->process($shop);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, app(UsageQuota::class)->summary($shop)['reserved']);
        $this->assertSame('RESERVED', AutomationDelivery::sole()->quota_status);
        Mail::assertNothingSent();
    }
}
