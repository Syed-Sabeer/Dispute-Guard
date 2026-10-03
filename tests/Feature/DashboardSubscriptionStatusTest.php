<?php

namespace Tests\Feature;

use App\Services\Billing\BillingServiceInterface;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardSubscriptionStatusTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['chargeguard.billing_enabled' => true, 'chargeguard.billing_enforced' => true,
            'chargeguard.test_mode' => false, 'shopify.partner_id' => '123',
            'shopify.partner_token' => 'synthetic-partner-token', 'shopify.app_id' => 'gid://shopify/App/456',
            'shopify.app_handle' => 'dispute-guard']);
        Http::preventStrayRequests();
    }

    private function activeSubscription(): array
    {
        return ['data' => ['activeSubscription' => [
            'cancelAtEndOfCycle' => false,
            'currentBillingCycle' => ['startTime' => now()->subDay()->toIso8601String(), 'endTime' => now()->addDays(29)->toIso8601String()],
            'items' => [['handle' => config('chargeguard.billing_items.starter'), 'price' => ['active' => false]]],
        ]]];
    }

    public static function preferences(): array
    {
        return [[false, true], [true, true], [false, false], [true, false]];
    }

    public function test_scheduled_cancellation_keeps_access_until_cycle_end(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-03T12:00:00Z'));
        $shop = $this->shop();
        $shop->update(['timezone' => 'Asia/Karachi']);
        $payload = $this->activeSubscription();
        $payload['data']['activeSubscription']['cancelAtEndOfCycle'] = true;
        $payload['data']['activeSubscription']['currentBillingCycle']['endTime'] = '2026-10-31T22:00:00Z';
        Http::fake(['partners.shopify.com/*' => Http::response($payload)]);
        $this->merchant($shop)->get('/dashboard')->assertOk()
            ->assertSee('Subscription cancellation scheduled')->assertSee('remains active until Nov 1, 2026')
            ->assertSee('target="_top">Manage plan', false)
            ->assertDontSee('Subscription inactive')->assertDontSee('Subscription verification is required.')
            ->assertViewHas('automationState', fn ($state) => $state['label'] === 'Enabled');
        $this->assertSame('CANCELING', $shop->fresh()->billing_status);
        Http::assertSent(fn ($request) => str_contains($request['query'], 'cancelAtEndOfCycle'));
        $this->merchant($shop)->get('/billing')->assertOk()->assertViewHas('entitled', true)
            ->assertSee('CANCELING')->assertDontSee('Automation requires a verified active subscription.');
        $this->travelTo(CarbonImmutable::parse('2026-10-31T22:00:00Z'));
        $this->merchant($shop)->get('/dashboard')->assertOk()->assertSee('Subscription inactive')
            ->assertDontSee('Subscription cancellation scheduled');
        $this->assertSame('INACTIVE', $shop->fresh()->billing_status);
        $this->assertNull($shop->fresh()->billing_period_end);
    }

    public function test_canceling_notice_handles_a_missing_end_date(): void
    {
        $shop = $this->shop();
        $shop->update(['billing_status' => 'CANCELING', 'billing_period_end' => null]);
        $this->mock(BillingServiceInterface::class, function ($mock) {
            $mock->shouldReceive('entitled')->once()->andReturn(false);
            $mock->shouldReceive('manageUrl')->once()->andReturn(null);
        });
        $this->merchant($shop)->get('/dashboard')->assertOk()
            ->assertSee('remains active until the end of the current billing period');
        Http::assertNothingSent();
    }

    #[DataProvider('preferences')]
    public function test_inactive_notice_is_independent_of_automation_and_onboarding(bool $enabled, bool $onboarded): void
    {
        $shop = $this->shop(['auto_email_enabled' => $enabled, 'onboarded_at' => $onboarded ? now() : null], $onboarded);
        // A reinstall may still have a cached ACTIVE value until Partner verification.
        $shop->update(['billing_status' => 'ACTIVE']);
        Http::fake(['partners.shopify.com/*' => Http::response(['data' => ['activeSubscription' => null]])]);
        $response = $this->merchant($shop)->get('/dashboard');
        $response->assertOk()->assertSee('Subscription inactive')
            ->assertSee('Your Dispute Guard subscription has expired or been canceled. Automatic customer follow-ups are paused. Choose a plan to continue.')
            ->assertSee('href="https://admin.shopify.com/store/'.$shop->handle().'/charges/dispute-guard/pricing_plans" target="_top">Choose a plan', false)
            ->assertSee($enabled ? 'Automation paused' : 'Disabled');
        $this->assertSame('INACTIVE', $shop->fresh()->billing_status);
        $this->assertSame($enabled, $shop->settings()->first()->auto_email_enabled);
    }

    public function test_unverified_notice_does_not_claim_cancellation(): void
    {
        $shop = $this->shop(['auto_email_enabled' => false]);
        Http::fake(['partners.shopify.com/*' => Http::response(['errors' => [['message' => 'unavailable']]])]);
        $this->merchant($shop)->get('/dashboard')->assertOk()
            ->assertSee('Subscription status unavailable')
            ->assertSee("We couldn't verify your Shopify subscription right now. Automatic customer follow-ups are paused. Refresh the Billing page or try again.")
            ->assertSee('href="/billing" target="_self">Open Billing', false)
            ->assertDontSee('expired or been canceled')->assertDontSee('Subscription inactive');
        $this->assertSame('UNVERIFIED', $shop->fresh()->billing_status);
    }

    public function test_active_subscription_has_no_warning_and_reactivation_removes_notice(): void
    {
        $shop = $this->shop();
        Http::fake(['partners.shopify.com/*' => Http::sequence()
            ->push($this->activeSubscription())
            ->push(['data' => ['activeSubscription' => null]])
            ->push($this->activeSubscription())]);
        $this->merchant($shop)->get('/dashboard')->assertOk()->assertViewHas('subscriptionNotice', null)
            ->assertDontSee('Subscription inactive')->assertDontSee('Subscription status unavailable');
        $this->merchant($shop)->get('/dashboard')->assertOk()->assertSee('Subscription inactive');
        $this->merchant($shop)->get('/dashboard')->assertOk()->assertViewHas('subscriptionNotice', null)
            ->assertDontSee('Subscription inactive')->assertDontSee('Subscription status unavailable');
        $this->assertSame('ACTIVE', $shop->fresh()->billing_status);
    }
}
