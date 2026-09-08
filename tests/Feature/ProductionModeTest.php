<?php

namespace Tests\Feature;

use App\Jobs\SendTestAutomationEmail;
use App\Models\Dispute;
use App\Models\EmailLog;
use App\Services\Billing\ShopifyAppPricingService;
use App\Services\DeploymentMode;
use App\Services\Disputes\AutomationResolver;
use App\Services\Email\EmailComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ProductionModeTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('env', 'production');
        config(['app.debug' => false, 'chargeguard.test_tools' => null, 'chargeguard.billing_enabled' => true,
            'chargeguard.prelaunch' => false, 'chargeguard.prelaunch_shops' => [], 'chargeguard.test_mode' => false]);
        Http::preventStrayRequests();
    }

    private function prelaunch($shop): void
    {
        config(['chargeguard.billing_enabled' => false, 'chargeguard.prelaunch' => true,
            'chargeguard.prelaunch_shops' => [$shop->shop_domain]]);
    }

    public function test_production_pages_have_clean_branding_navigation_and_empty_states(): void
    {
        $shop = $this->shop(['auto_email_enabled' => false]);
        $this->prelaunch($shop);
        config(['chargeguard.test_mode' => true]);
        $this->merchant($shop)->get('/dashboard')->assertOk()->assertSee('Dispute Guard')
            ->assertSee('No disputes yet')->assertSee('Automatic customer follow-ups are currently disabled.')
            ->assertDontSee('TEST MODE')->assertDontSee('Send a test email')->assertDontSee('Test Automation')
            ->assertDontSee('href="/billing"', false)->assertDontSee('ChargeGuard');
        $this->get('/disputes')->assertOk()->assertSee('No disputes found.')->assertDontSee('value="synthetic"', false)->assertDontSee('value="demo"', false);
        $this->get('/disputes?automation_status=MANUAL_REVIEW')->assertSee('No disputes currently require manual review.');
        $this->get('/email-logs')->assertOk()->assertSee('No customer emails have been sent yet.');
        $this->get('/onboarding')->assertOk()->assertDontSee('test email')->assertDontSee('billing plan');
        $this->get('/templates')->assertOk()->assertDontSee('Send test');
        $this->get('/billing')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_test_tools_are_blocked_server_side_and_can_be_explicitly_enabled(): void
    {
        $shop = $this->shop();
        $this->merchant($shop)->get('/test-automation')->assertNotFound();
        $this->postJson('/test-automation/send', [])->assertNotFound();
        config(['chargeguard.test_tools' => true]);
        $this->get('/test-automation')->assertOk();
        config(['chargeguard.test_tools' => null]);
        $this->app->instance('env', 'local');
        $this->assertTrue(DeploymentMode::testTools());
        $this->get('/test-automation')->assertOk();
    }

    public function test_disabling_tools_cancels_already_queued_test_mail(): void
    {
        Mail::fake();
        $shop = $this->shop();
        $log = EmailLog::factory()->create(['shop_id' => $shop->id, 'type' => 'test', 'status' => 'QUEUED']);
        app()->call([new SendTestAutomationEmail($log->id, 'never-decrypt-disabled-input'), 'handle']);
        $this->assertSame('CANCELLED', $log->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_synthetic_records_are_hidden_even_by_direct_url(): void
    {
        $shop = $this->shop();
        $demo = Dispute::factory()->create(['shop_id' => $shop->id, 'source' => 'demo', 'order_name' => 'DEMO-SECRET']);
        $log = EmailLog::factory()->create(['shop_id' => $shop->id, 'dispute_id' => $demo->id, 'type' => 'automatic', 'subject' => 'DEMO-EMAIL']);
        $this->merchant($shop)->get('/dashboard')->assertDontSee('DEMO-SECRET');
        $this->get('/disputes?source=demo')->assertDontSee('DEMO-SECRET');
        $this->get('/disputes/'.$demo->id)->assertNotFound();
        $this->get('/email-logs')->assertDontSee('DEMO-EMAIL');
        $this->get('/email-logs/'.$log->id)->assertNotFound();
        config(['chargeguard.demo_mode' => true]);
        $this->get('/demo/dashboard')->assertNotFound();
    }

    public function test_prelaunch_is_allowlisted_and_fails_closed_without_explicit_flag(): void
    {
        $shop = $this->shop();
        $this->prelaunch($shop);
        $billing = app(ShopifyAppPricingService::class);
        $this->assertTrue($billing->entitled($shop));
        $this->assertSame('UNVERIFIED', $shop->fresh()->billing_status);
        $this->merchant($shop)->get('/dashboard')->assertOk();
        config(['chargeguard.prelaunch' => false]);
        $this->assertFalse($billing->entitled($shop));
        $this->get('/dashboard')->assertForbidden();
        config(['chargeguard.prelaunch' => true, 'chargeguard.prelaunch_shops' => []]);
        $this->get('/dashboard')->assertForbidden();
        Http::assertNothingSent();
    }

    private function settingsPayload(): array
    {
        return ['store_display_name' => 'Store', 'support_email' => 'support@example.com', 'reply_to_email' => 'help@example.com',
            'auto_email_enabled' => true, 'test_mode' => false, 'timezone' => 'UTC', 'templates_reviewed' => true];
    }

    public function test_onboarding_completes_without_test_mail_and_enabling_is_explicit(): void
    {
        $shop = $this->shop(['auto_email_enabled' => false, 'onboarded_at' => null]);
        $this->prelaunch($shop);
        $this->merchant($shop)->putJson('/settings', array_replace($this->settingsPayload(), ['auto_email_enabled' => false]))->assertOk();
        $this->assertNotNull($shop->settings->fresh()->onboarded_at);
        $this->assertFalse($shop->settings->fresh()->auto_email_enabled);
        $this->get('/dashboard')->assertDontSee('Finish setting up');
        $this->putJson('/settings', $this->settingsPayload())->assertOk();
        $this->assertTrue($shop->settings->fresh()->auto_email_enabled);
        $this->assertSame(0, $shop->emailLogs()->count());
        Http::assertNothingSent();
    }

    public function test_billing_enabled_blocks_activation_without_subscription(): void
    {
        $shop = $this->shop(['auto_email_enabled' => false]);
        config(['shopify.partner_token' => null, 'chargeguard.billing_enforced' => false]);
        $this->merchant($shop)->putJson('/settings', $this->settingsPayload())->assertStatus(402);
        $this->get('/dashboard')->assertSee('Billing');
        $this->assertFalse($shop->settings->fresh()->auto_email_enabled);
    }

    public function test_global_mail_safety_switch_and_ordinary_safety_checks_still_apply(): void
    {
        $shop = $this->shop();
        $this->prelaunch($shop);
        $dispute = Dispute::factory()->create(['shop_id' => $shop->id, 'source' => 'shopify', 'reason' => 'PRODUCT_NOT_RECEIVED', 'shipping_state' => 'IN_TRANSIT', 'status' => 'NEEDS_RESPONSE']);
        $resolver = app(AutomationResolver::class);
        $template = $resolver->template($shop, $dispute->reason, $dispute->shipping_state);
        $this->assertNull($resolver->blocked($shop, $dispute, $template));
        config(['chargeguard.test_mode' => true]);
        $this->assertSame('Test mode prevents production email.', $resolver->blocked($shop, $dispute, $template));
        config(['chargeguard.test_mode' => false]);
        $shop->settings()->update(['auto_email_enabled' => false]);
        $this->assertNotNull($resolver->blocked($shop, $dispute, $template));
        $dispute->update(['shipping_state' => 'UNKNOWN']);
        $this->assertNotNull($resolver->blocked($shop, $dispute, $template));
    }

    public function test_customer_mail_has_no_test_prefix_and_keeps_merchant_reply_to(): void
    {
        $shop = $this->shop();
        $template = $shop->emailTemplates()->first();
        $composer = app(EmailComposer::class);
        $variables = ['order_number' => '#1001', 'store_name' => 'Real Store'];
        $production = $composer->compose($shop, $template, $variables);
        $test = $composer->compose($shop, $template, $variables, true);
        $this->assertStringNotContainsString('[TEST]', $production['subject']);
        $this->assertStringNotContainsString('TEST MODE', $production['body']);
        $this->assertStringStartsWith('[TEST]', $test['subject']);
        $production['mailable']->build();
        $this->assertTrue($production['mailable']->hasReplyTo('support@example.com'));
    }

    public function test_exception_reporting_does_not_log_sensitive_messages(): void
    {
        Log::spy();
        report(new \RuntimeException('token=secret customer@example.com'));
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $message === 'Application operation failed'
            && ! str_contains(json_encode($context), 'secret') && ! str_contains(json_encode($context), 'customer@example.com'));
    }

    public function test_preflight_accepts_private_prelaunch_but_rejects_unsafe_production_flags(): void
    {
        config(['queue.default' => 'database', 'shopify.api_key' => 'configured', 'shopify.api_secret' => 'configured',
            'senders.required' => true,
            'app.url' => 'https://merchant-app.test', 'mail.default' => 'postmark', 'mail.from.address' => 'sender@merchant-app.test',
            'services.postmark.token' => 'test-server-token', 'services.postmark.account_token' => 'test-account-token',
            'session.secure' => true, 'session.same_site' => 'none', 'chargeguard.demo_mode' => false,
            'chargeguard.billing_enabled' => false, 'chargeguard.prelaunch' => true, 'chargeguard.prelaunch_shops' => ['private.myshopify.com']]);
        $this->artisan('chargeguard:health-check')->expectsOutput('WARN Billing disabled for prelaunch; public launch is not permitted')->assertExitCode(0);
        config(['services.postmark.token' => '', 'services.postmark.account_token' => '']);
        $this->artisan('chargeguard:health-check')->expectsOutput('FAIL Postmark send token configured')
            ->expectsOutput('FAIL Postmark account token configured')->assertExitCode(1);
        config(['chargeguard.test_tools' => true, 'chargeguard.demo_mode' => true]);
        $this->artisan('chargeguard:health-check')->expectsOutput('FAIL Demo disabled')->expectsOutput('FAIL Test tools disabled')->assertExitCode(1);
    }
}
