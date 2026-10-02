<?php

namespace Tests\Feature;

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
            'currentBillingCycle' => ['startTime' => now()->subDay()->toIso8601String(), 'endTime' => now()->addDays(29)->toIso8601String()],
            'items' => [['handle' => config('chargeguard.billing_items.starter'), 'price' => ['active' => false]]],
        ]]];
    }

    public static function preferences(): array
    {
        return [[false, true], [true, true], [false, false], [true, false]];
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
