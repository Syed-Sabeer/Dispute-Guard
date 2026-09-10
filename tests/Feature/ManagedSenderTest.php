<?php

namespace Tests\Feature;

use App\Exceptions\EmailProviderException;
use App\Jobs\SendDisputeCustomerEmail;
use App\Models\AutomationDelivery;
use App\Services\Disputes\DisputeProcessor;
use App\Services\Email\EmailComposer;
use App\Services\Email\MerchantSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManagedSenderTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'postmark', 'services.postmark.token' => 'test-server-token',
            'services.postmark.account_token' => null, 'senders.managed_address' => 'disputes@managed-domain.com',
            'senders.managed_domain' => 'managed-domain.com', 'chargeguard.test_mode' => false]);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/email' => Http::response(['ErrorCode' => 0, 'MessageID' => '12345678-1234-1234-1234-123456789abc'])]);
    }

    private function queueFor($shop): AutomationDelivery
    {
        Queue::fake();
        $this->fakeShopify($this->order());
        app(DisputeProcessor::class)->process($shop, '789', true);

        return AutomationDelivery::sole();
    }

    public function test_verified_merchant_mail_uses_store_alias_reply_to_and_no_domain_api(): void
    {
        $shop = $this->shop(['store_display_name' => 'My Store', 'reply_to_email' => 'help@merchant-mail.com']);
        $delivery = $this->queueFor($shop);
        $job = new SendDisputeCustomerEmail($delivery->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame('SENT', $delivery->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['From'] === '"My Store" <support@fixture-merchant.com>' && $r['ReplyTo'] === 'help@merchant-mail.com');
        $this->assertDatabaseCount('merchant_email_senders', 1);
        $this->assertDatabaseCount('email_sending_domains', 1);
    }

    public function test_names_are_tenant_specific_and_cannot_inject_headers(): void
    {
        $a = $this->shop(['store_display_name' => "Shop A\r\n<Injected>"]);
        $b = $this->shop(['store_display_name' => 'Shop B']);
        $service = app(MerchantSenderService::class);
        $first = $service->identity($a);
        $second = $service->identity($b);
        $this->assertSame('support@fixture-merchant.com', $first['email']);
        $this->assertSame('support@fixture-merchant.com', $second['email']);
        $this->assertNotSame($first['name'], $second['name']);
        $this->assertDoesNotMatchRegularExpression('/[\r\n<>]/', $first['name']);
        $this->assertSame('Shop B', $second['name']);
        Http::assertNothingSent();
    }

    public function test_store_alias_changes_cancel_queued_merchant_identity(): void
    {
        $shop = $this->shop();
        $delivery = $this->queueFor($shop);
        $shop->settings()->update(['store_display_name' => 'Changed Store']);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_system_address_changes_do_not_change_queued_merchant_identity(): void
    {
        $shop = $this->shop();
        $delivery = $this->queueFor($shop);
        config(['senders.managed_address' => 'new@managed-domain.com']);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('SENT', $delivery->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_reply_to_changes_cancel_queued_identity(): void
    {
        $shop = $this->shop();
        $delivery = $this->queueFor($shop);
        $shop->settings()->update(['reply_to_email' => 'changed@merchant-mail.com']);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_domain_mismatch_blocks_managed_sending(): void
    {
        config(['senders.managed_domain' => 'other-domain.com']);
        $service = app(MerchantSenderService::class);
        $this->assertFalse($service->managedConfigured());
        $this->expectException(EmailProviderException::class);
        $service->systemIdentity();
    }

    public function test_no_dns_onboarding_and_activation_preserve_safety_mode(): void
    {
        $shop = $this->shop(['onboarded_at' => null, 'auto_email_enabled' => false]);
        $this->merchant($shop)->get('/onboarding')->assertOk()->assertSee('Customer emails are sent from your verified business email.')->assertDontSee('Authenticate your sending domain');
        $data = ['store_display_name' => 'Store', 'support_email' => 'support@merchant-mail.com', 'auto_email_enabled' => true,
            'test_mode' => false, 'timezone' => 'UTC', 'templates_reviewed' => true];
        config(['chargeguard.test_mode' => true]);
        $this->putJson('/settings', $data)->assertUnprocessable();
        config(['chargeguard.test_mode' => false]);
        $this->putJson('/settings', $data)->assertOk();
        $this->assertNotNull($shop->settings()->first()->onboarded_at);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        $this->get('/')->assertOk()->assertSee('Enabled')->assertSee('Customer emails are sent from your verified business email.');
        Http::assertNothingSent();
    }

    public function test_invalid_reply_to_uses_valid_support_address(): void
    {
        $shop = $this->shop(['reply_to_email' => "bad\r\nBcc: victim", 'support_email' => 'support@merchant-mail.com']);
        $message = app(EmailComposer::class)->compose($shop, $shop->emailTemplates()->first(), []);
        $message['mailable']->build();
        $this->assertTrue($message['mailable']->hasReplyTo('support@merchant-mail.com'));
    }

    public function test_smoke_test_uses_explicit_managed_address(): void
    {
        config(['mail.from.address' => 'old@legacy-domain.com']);
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => 'operator@own-domain.com', '--force' => true])->assertExitCode(0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['From'], '<disputes@managed-domain.com>'));
    }

    public function test_legacy_snapshot_is_never_reinterpreted_as_managed_identity(): void
    {
        $shop = $this->shop();
        $delivery = $this->queueFor($shop);
        $delivery->update(['sender_identity_hash' => hash('sha256', json_encode([null, null, config('mail.from.address'), config('mail.from.name')]))]);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        Http::assertNothingSent();
    }
}
