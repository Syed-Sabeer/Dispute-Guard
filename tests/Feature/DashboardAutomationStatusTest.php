<?php

namespace Tests\Feature;

use App\Models\SubscriptionUsagePeriod;
use App\Services\Billing\BillingServiceInterface;
use App\Services\Billing\UsageQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardAutomationStatusTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    /** @dataProvider statuses */
    public function test_effective_dashboard_status_preserves_preference(string $scenario, string $label, string $description): void
    {
        config([
            'chargeguard.test_mode' => false,
            'chargeguard.billing_enabled' => true,
            'senders.managed_address' => 'notifications@disputeguard.com',
            'senders.managed_domain' => 'disputeguard.com',
        ]);
        Http::preventStrayRequests();
        $shop = $this->shop();
        switch ($scenario) {
            case 'disabled':
                $shop->settings()->update(['auto_email_enabled' => false]);
                config(['chargeguard.test_mode' => true]);
                break;
            case 'global_test':
                config(['chargeguard.test_mode' => true]);
                break;
            case 'shop_test':
                $shop->settings()->update(['test_mode' => true]);
                break;
            case 'onboarding':
                $shop->settings()->update(['onboarded_at' => null]);
                break;
            case 'sender':
                config(['senders.managed_address' => 'invalid']);
                break;
            case 'missing_period':
                $shop->update(['billing_period_start' => null, 'billing_period_end' => null]);
                break;
            case 'expired_period':
                $shop->update(['billing_period_start' => now()->subDays(30), 'billing_period_end' => now()]);
                break;
            case 'quota':
            case 'reserved_quota':
                app(UsageQuota::class)->summary($shop);
                SubscriptionUsagePeriod::where('shop_id', $shop->id)->update([
                    'consumed' => $scenario === 'quota' ? 1000 : 999,
                    'reserved' => $scenario === 'reserved_quota' ? 1 : 0,
                ]);
                break;
        }
        // Cached billing/usage data must not override failed live verification.
        $shop->update(['billing_status' => 'ACTIVE']);
        $this->mock(BillingServiceInterface::class, fn ($mock) => $mock
            ->shouldReceive('entitled')->once()->andReturn($scenario !== 'billing'));
        $response = $this->merchant($shop)->get('/');
        $response->assertOk()->assertViewHas('automationState', fn ($state) => $state['label'] === $label && str_contains($state['description'], $description));
        $response->assertSee($label)->assertSee($description);
        if ($label !== 'Enabled') {
            $response->assertDontSee('>Enabled</s-badge>', false);
        }
        if (in_array($scenario, ['quota', 'reserved_quota'], true)) {
            $response->assertSee('Upgrade plan');
        }
        $this->assertSame($scenario !== 'disabled', $shop->settings()->first()->auto_email_enabled);
        Http::assertNothingSent();
    }

    public static function statuses(): array
    {
        return [
            'preference wins' => ['disabled', 'Disabled', 'Automatic customer follow-ups are currently disabled.'],
            'global test mode' => ['global_test', 'Automation paused', 'Customer emails are blocked while test mode is enabled.'],
            'shop test mode' => ['shop_test', 'Automation paused', 'Customer emails are blocked while test mode is enabled.'],
            'onboarding' => ['onboarding', 'Automation paused', 'Complete onboarding'],
            'managed sender unavailable' => ['sender', 'Automation paused', 'Email delivery is unavailable.'],
            'verification failed with cached active status' => ['billing', 'Automation paused', 'Subscription verification is required.'],
            'missing period' => ['missing_period', 'Automation paused', 'Usage period is unavailable.'],
            'expired period' => ['expired_period', 'Automation paused', 'Usage period is unavailable.'],
            'quota consumed' => ['quota', 'Automation paused', 'Monthly follow-up limit reached.'],
            'quota reserved' => ['reserved_quota', 'Automation paused', 'Monthly follow-up limit reached.'],
            'eligible without custom DNS' => ['enabled', 'Enabled', 'Eligible new disputes receive customer follow-ups after safety checks.'],
        ];
    }
}
