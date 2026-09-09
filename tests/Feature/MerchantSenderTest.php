<?php

namespace Tests\Feature;

use App\Exceptions\EmailProviderException;
use App\Jobs\SendDisputeCustomerEmail;
use App\Models\AutomationDelivery;
use App\Models\EmailLog;
use App\Services\Disputes\AutomationResolver;
use App\Services\Disputes\DisputeProcessor;
use App\Services\Email\EmailComposer;
use App\Services\Email\MerchantSenderService;
use App\Services\Email\PostmarkEmailProvider;
use App\Services\Email\SenderDnsVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MerchantSenderTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    private const MESSAGE_ID = '12345678-1234-1234-1234-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'postmark', 'services.postmark.token' => 'private-server-token', 'services.postmark.account_token' => 'private-account-token',
            'mail.from.address' => 'notifications@disputeguard-mail.com', 'mail.from.name' => 'Dispute Guard', 'senders.managed_address' => null, 'senders.managed_domain' => null, 'chargeguard.test_mode' => false]);
        Http::preventStrayRequests();
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(true));
    }

    private function domain(string $name = 'store-one.com', int $id = 81234567, bool $verified = true): array
    {
        return ['ID' => $id, 'Name' => $name, 'DKIMVerified' => $verified, 'WeakDKIM' => false,
            'DKIMHost' => 'selector._domainkey.'.$name, 'DKIMTextValue' => 'k=rsa; p=provider-key',
            'DKIMPendingHost' => '', 'DKIMPendingTextValue' => '', 'ReturnPathDomain' => 'pm-bounces.'.$name,
            'ReturnPathDomainCNAMEValue' => 'provider-return.example.net', 'ReturnPathDomainVerified' => $verified];
    }

    private function fakeProvider(): void
    {
        Http::fake(['api.postmarkapp.com/*' => function ($request) {
            if (str_ends_with($request->url(), '/email')) {
                return Http::response(['ErrorCode' => 0, 'MessageID' => self::MESSAGE_ID]);
            }
            $name = $request['Name'] ?? 'store-one.com';

            return Http::response($this->domain($name, $name === 'store-one.com' ? 81234567 : 81234568));
        }]);
    }

    private function verified($shop)
    {
        $this->fakeProvider();
        $service = app(MerchantSenderService::class);
        $service->save($shop, 'ABC Store', 'Support@Store-One.com');

        return $service->check($shop);
    }

    public function test_save_normalizes_identity_and_only_displays_safe_dns_fields(): void
    {
        $this->fakeProvider();
        $shop = $this->shop();
        $this->merchant($shop)->putJson('/settings/email-sender', ['sender_name' => 'ABC Store', 'sender_email' => 'Support@Store-One.com'])->assertOk()
            ->assertDontSee('private-account-token')->assertDontSee('private-server-token');
        $sender = $shop->emailSender()->sole();
        $this->assertSame('support@store-one.com', $sender->sender_email);
        $this->assertSame('store-one.com', $sender->sendingDomain->domain);
        $this->assertSame('PENDING', $sender->verification_status);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        $this->get('/settings/email-sender')->assertOk()->assertSee('selector._domainkey.store-one.com')->assertSee('provider-return.example.net')
            ->assertDontSee('private-account-token')->assertDontSee('private-server-token')->assertDontSee('81234567');
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.postmarkapp.com/domains'
            && $r->hasHeader('X-Postmark-Account-Token', 'private-account-token') && ! $r->hasHeader('X-Postmark-Server-Token'));
        $this->assertStringNotContainsString('private-account-token', $sender->toJson());
    }

    public static function invalidEmails(): array
    {
        return array_map(fn ($v) => [$v], ['bad', "a@store-one.com\r\nBcc: victim@store-two.com", 'a@[127.0.0.1]', 'a@localhost', 'a@shop.local', 'a@shop.invalid', 'a@example.com', 'a@shop.test',
            'a@gmail.com', 'a@outlook.com', 'a@yahoo.com', 'a@store.myshopify.com', 'a@Ã©xample.com', 'a@-bad.com', 'a@store-one.com ', "a@store-one.com\0"]);
    }

    #[DataProvider('invalidEmails')]
    public function test_invalid_sender_addresses_are_rejected(string $email): void
    {
        $this->merchant($this->shop())->putJson('/settings/email-sender', ['sender_name' => 'Store', 'sender_email' => $email])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_name_injection_and_browser_verification_are_rejected(): void
    {
        $this->merchant($this->shop())->putJson('/settings/email-sender', ['sender_name' => "Store\r\nBcc: victim", 'sender_email' => 'support@store-one.com'])->assertUnprocessable();
        $this->putJson('/settings/email-sender', ['sender_name' => 'Store', 'sender_email' => 'support@store-one.com', 'verification_status' => 'VERIFIED', 'provider_domain_id' => 12])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_verification_requires_provider_flags_and_current_dns(): void
    {
        $this->fakeProvider();
        $shop = $this->shop();
        $service = app(MerchantSenderService::class);
        $service->save($shop, 'Store', 'support@store-one.com');
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(false));
        $pending = app(MerchantSenderService::class)->check($shop);
        $this->assertSame('PENDING', $pending->verification_status);
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop));
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(true));
        $this->merchant($shop)->postJson('/settings/email-sender/verify')->assertOk();
        $this->assertSame('VERIFIED', $shop->emailSender()->first()->verification_status);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
    }

    public function test_false_provider_flags_cannot_be_overridden_by_dns(): void
    {
        $shop = $this->shop();
        $this->fakeProvider();
        app(MerchantSenderService::class)->save($shop, 'Store', 'support@store-one.com');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/*' => Http::response($this->domain(verified: false))]);
        $this->assertSame('PENDING', app(MerchantSenderService::class)->check($shop)->verification_status);
    }

    public function test_shared_domain_requires_separate_shop_ownership_proof(): void
    {
        $a = $this->shop();
        $verified = $this->verified($a);
        $b = $this->shop();
        $second = app(MerchantSenderService::class)->save($b, 'Second Store', 'help@store-one.com');
        $this->assertSame($verified->email_sending_domain_id, $second->email_sending_domain_id);
        $this->assertNotSame($verified->ownership_value, $second->ownership_value);
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturnUsing(fn ($host) => $host !== $second->ownership_host));
        $this->assertSame('PENDING', app(MerchantSenderService::class)->check($b)->verification_status);
        $this->assertTrue(app(MerchantSenderService::class)->ready($a));
        $this->merchant($b)->get('/settings/email-sender')->assertDontSee('ABC Store')->assertDontSee($verified->ownership_value);
        $this->putJson('/settings/email-sender', ['sender_name' => 'Impersonation', 'sender_email' => 'help@store-one.com', 'shop_id' => $a->id])->assertUnprocessable();
        Http::assertSentCount(5); // one creation, two checks per tenant; shared domain not recreated
    }

    public function test_reusing_an_existing_account_domain_does_not_confer_ownership(): void
    {
        Http::fake(['api.postmarkapp.com/*' => Http::sequence()
            ->push(['ErrorCode' => 512], 422)
            ->push(['TotalCount' => 1, 'Domains' => [['ID' => 81234567, 'Name' => 'store-one.com']]])
            ->push($this->domain())]);
        $sender = app(MerchantSenderService::class)->save($this->shop(), 'Store', 'support@store-one.com');
        $this->assertSame(81234567, $sender->sendingDomain->provider_domain_id);
        $this->assertSame('PENDING', $sender->verification_status);
        $this->assertFalse($sender->ownership_verified);
    }

    public function test_same_domain_change_preserves_verification_and_new_domain_pauses(): void
    {
        $shop = $this->shop();
        $old = $this->verified($shop);
        $service = app(MerchantSenderService::class);
        $same = $service->save($shop, 'New Name', 'help@store-one.com');
        $this->assertSame('VERIFIED', $same->verification_status);
        $this->assertSame($old->ownership_value, $same->ownership_value);
        Http::assertSentCount(3);
        $new = $service->save($shop, 'New Name', 'help@store-two.com');
        $this->assertSame('PENDING', $new->verification_status);
        $this->assertNotSame($old->ownership_value, $new->ownership_value);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        $this->assertDatabaseCount('email_sending_domains', 2);
    }

    public function test_from_domain_mismatch_and_expired_verification_are_not_ready(): void
    {
        $shop = $this->shop();
        $sender = $this->verified($shop);
        $sender->update(['sender_email' => 'support@other-store.com']);
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop));
        $sender->update(['sender_email' => 'support@store-one.com', 'last_checked_at' => now()->subMinutes(16)]);
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop));
        $identity = app(MerchantSenderService::class)->identity($shop);
        $this->assertSame('support@store-one.com', $identity['email']);
        Http::assertSentCount(5);
    }

    public function test_provider_outage_expires_identity_without_leaking_secrets(): void
    {
        $shop = $this->shop();
        $sender = $this->verified($shop);
        $shop->settings()->update(['auto_email_enabled' => true]);
        $sender->update(['last_checked_at' => now()->subMinutes(16)]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/*' => Http::response(['Message' => 'private-account-token customer@example.com'], 500)]);
        $identity = app(MerchantSenderService::class)->identity($shop);
        $this->assertSame('notifications@disputeguard-mail.com', $identity['email']);
        $this->assertStringNotContainsString('private-account-token', json_encode($identity));
        $this->assertSame('VERIFIED', $sender->fresh()->verification_status);
        $this->assertNotNull($sender->fresh()->verification_refresh_failed_at);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
    }

    public function test_sender_disconnect_requires_confirmation_and_never_deletes_provider_domain(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $this->merchant($shop)->postJson('/settings/email-sender/disconnect')->assertUnprocessable();
        $this->postJson('/settings/email-sender/disconnect', ['confirmed' => true])->assertOk();
        $this->assertSame('REMOVED', $shop->emailSender()->first()->verification_status);
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop));
        $this->assertDatabaseCount('email_sending_domains', 1);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    private function queuedDelivery(): array
    {
        $shop = $this->shop();
        $this->verified($shop);
        $shop->settings()->update(['auto_email_enabled' => true]);
        Queue::fake();
        $this->fakeShopify($this->order());
        app(DisputeProcessor::class)->process($shop, '789', true);

        return [$shop, AutomationDelivery::sole()];
    }

    public function test_stale_verified_sender_remains_verified_in_all_merchant_pages(): void
    {
        $shop = $this->shop();
        $sender = $this->verified($shop);
        $sender->update(['last_checked_at' => now()->subMinutes(16)]);
        $service = app(MerchantSenderService::class);
        $this->assertTrue($service->isVerified($shop));
        $this->assertFalse($service->isVerificationFresh($shop));
        app()->detectEnvironment(fn () => 'production');
        config(['chargeguard.prelaunch' => true, 'chargeguard.prelaunch_shops' => [$shop->shop_domain]]);
        $this->merchant($shop);
        foreach (['/', '/settings', '/onboarding', '/settings/email-sender'] as $path) {
            $this->get($path)->assertOk()->assertSee('no DNS setup required')->assertDontSee('Verification required')
                ->assertDontSee('private-account-token')->assertDontSee('81234567');
        }
        Http::assertSentCount(3); // Rendering never makes verification calls.
    }

    public function test_stale_refresh_succeeds_before_real_transport_send(): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        $shop->emailSender()->update(['last_checked_at' => now()->subMinutes(16)]);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertTrue(app(MerchantSenderService::class)->isVerificationFresh($shop));
        Http::assertSentCount(6);
    }

    public static function outageTypes(): array
    {
        return [['provider'], ['dns']];
    }

    #[DataProvider('outageTypes')]
    public function test_outage_blocks_current_mail_but_recovers_without_changing_preference(string $type): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        $shop->emailSender()->update(['last_checked_at' => now()->subMinutes(16)]);
        if ($type === 'provider') {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['api.postmarkapp.com/*' => Http::response([], 503)]);
        } else {
            $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andThrow(new EmailProviderException('TRANSIENT_VERIFICATION_FAILURE')));
        }
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        $this->assertSame('MANUAL_REVIEW', $delivery->dispute->fresh()->automation_status);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        $service = app(MerchantSenderService::class);
        $this->assertTrue($service->isVerified($shop));
        $this->assertFalse($service->isVerificationFresh($shop));
        $this->assertStringContainsString('temporarily unavailable', $service->statusLabel($shop));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeProvider();
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(true));
        $service = app(MerchantSenderService::class);
        $this->assertSame('support@store-one.com', $service->identity($shop)['email']);
        $this->assertNull($shop->emailSender()->first()->verification_refresh_failed_at);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        $resolver = app(AutomationResolver::class);
        $this->assertNull($resolver->blocked($shop, $delivery->dispute->fresh(), $delivery->template));
        // Recovery restores eligibility, never replays the cancelled dispute.
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public static function dnsRecords(): array
    {
        return [['dkim'], ['return_path'], ['ownership']];
    }

    #[DataProvider('dnsRecords')]
    public function test_genuine_record_loss_blocks_from_but_preserves_preference(string $record): void
    {
        $shop = $this->shop();
        $sender = $this->verified($shop);
        $shop->settings()->update(['auto_email_enabled' => true]);
        $host = match ($record) {
            'dkim' => $sender->sendingDomain->dkim_host,
            'return_path' => $sender->sendingDomain->return_path_host,
            default => $sender->ownership_host,
        };
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturnUsing(fn ($name) => $name !== $host));
        $service = app(MerchantSenderService::class);
        $this->assertSame('PENDING', $service->check($shop)->verification_status);
        $this->assertFalse($service->isVerified($shop));
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        $this->assertSame('notifications@disputeguard-mail.com', $service->identity($shop)['email']);
    }

    public function test_final_guard_refreshes_if_ttl_expires_after_composition(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $service = app(MerchantSenderService::class);
        $identity = $service->identity($shop);
        $this->travel(16)->minutes();
        $this->assertSame('sent', $service->guard($shop, $identity, false, fn () => 'sent'));
        Http::assertSentCount(5);
    }

    public function test_failed_manual_recheck_invalidates_freshness_without_losing_verification(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $shop->settings()->update(['auto_email_enabled' => true]);
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andThrow(new EmailProviderException('TRANSIENT_VERIFICATION_FAILURE')));
        $service = app(MerchantSenderService::class);
        try {
            $service->check($shop);
            $this->fail('Recheck should fail.');
        } catch (EmailProviderException $e) {
            $this->assertSame('TRANSIENT_VERIFICATION_FAILURE', $e->category);
        }
        $this->assertTrue($service->isVerified($shop));
        $this->assertFalse($service->isVerificationFresh($shop));
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
    }

    public function test_final_guard_blocks_when_refresh_fails_after_composition(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $identity = app(MerchantSenderService::class)->identity($shop);
        $this->travel(16)->minutes();
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andThrow(new EmailProviderException('TRANSIENT_VERIFICATION_FAILURE')));
        $this->expectException(EmailProviderException::class);
        app(MerchantSenderService::class)->guard($shop, $identity, false, fn () => $this->fail('Unverified send occurred.'));
    }

    public function test_same_domain_sender_edit_while_queued_cancels_delivery(): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        app(MerchantSenderService::class)->save($shop, 'New Store Name', 'help@store-one.com');
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        $this->assertStringContainsString('sender changed', $delivery->fresh()->failure_reason);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public function test_automatic_email_uses_verified_from_reply_to_and_records_provider_id_once(): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        $job = new SendDisputeCustomerEmail($delivery->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame(self::MESSAGE_ID, EmailLog::sole()->provider_message_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/email') && $r['From'] === '"ABC Store" <support@store-one.com>'
            && $r['ReplyTo'] === 'support@example.com' && ! str_starts_with($r['Subject'], '[TEST]')
            && $r->hasHeader('X-Postmark-Server-Token', 'private-server-token') && ! $r->hasHeader('X-Postmark-Account-Token'));
        Http::assertSentCount(4);
    }

    public function test_no_custom_domain_allows_automatic_processing_and_activation(): void
    {
        $shop = $this->shop();
        Queue::fake();
        $this->fakeShopify($this->order());
        $dispute = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame('EMAIL_QUEUED', $dispute->automation_status);
        $this->assertDatabaseCount('automation_deliveries', 1);
        $this->merchant($shop)->putJson('/settings', ['store_display_name' => 'Store', 'support_email' => 'help@example.com', 'auto_email_enabled' => true,
            'test_mode' => false, 'timezone' => 'UTC', 'templates_reviewed' => true])->assertOk();
        $this->assertNotNull($shop->settings()->first()->onboarded_at);
        Http::assertNothingSent();
    }

    public static function sendFailures(): array
    {
        return [[422, 'FAILED'], [500, 'UNKNOWN'], [200, 'UNKNOWN']];
    }

    #[DataProvider('sendFailures')]
    public function test_provider_failure_never_retries_uncertain_or_rejected_delivery(int $httpStatus, string $expected): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/email' => Http::response(['ErrorCode' => 300, 'Message' => 'private-server-token'], $httpStatus)]);
        $job = new SendDisputeCustomerEmail($delivery->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame($expected, $delivery->fresh()->status);
        $this->assertSame('MANUAL_REVIEW', $delivery->dispute->fresh()->automation_status);
        $this->assertStringNotContainsString('private-server-token', $delivery->fresh()->failure_reason);
        Http::assertSentCount(1);
    }

    public function test_test_mail_uses_labelled_fallback_without_verified_sender(): void
    {
        $shop = $this->shop();
        $message = app(EmailComposer::class)->compose($shop, $shop->emailTemplates()->first(), [], true);
        $message['mailable']->build();
        $this->assertTrue($message['mailable']->hasFrom('notifications@disputeguard-mail.com', 'Demo Store'));
        $this->assertStringStartsWith('[TEST]', $message['subject']);
        $production = app(EmailComposer::class)->compose($shop, $shop->emailTemplates()->first(), []);
        $this->assertSame('notifications@disputeguard-mail.com', $production['identity']['email']);
    }

    public function test_send_timeout_is_uncertain_and_sanitized(): void
    {
        Http::fake(fn () => throw new ConnectionException('private-server-token'));
        try {
            app(PostmarkEmailProvider::class)->send([]);
            $this->fail('Timeout accepted');
        } catch (EmailProviderException $e) {
            $this->assertSame('DELIVERY_OUTCOME_UNKNOWN', $e->category);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('private-server-token', $e->getMessage());
        }
    }

    public function test_optional_fallback_never_uses_an_unverified_merchant_from(): void
    {
        $this->fakeProvider();
        $shop = $this->shop();
        app(MerchantSenderService::class)->save($shop, 'Store', 'support@store-one.com');
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(false));
        $this->assertSame('notifications@disputeguard-mail.com', app(MerchantSenderService::class)->identity($shop)['email']);
    }

    public function test_disconnect_cancels_already_queued_mail(): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        app(MerchantSenderService::class)->disconnect($shop);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public function test_new_mail_after_custom_dns_loss_uses_managed_sender(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(false));
        app(MerchantSenderService::class)->check($shop);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        Queue::fake();
        $this->fakeShopify($this->order());
        app(DisputeProcessor::class)->process($shop, '789', true);
        $delivery = AutomationDelivery::sole();
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('SENT', $delivery->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/email') && $r['From'] === '"Demo Store" <notifications@disputeguard-mail.com>');
    }

    public function test_managed_queue_never_switches_to_newly_verified_custom_sender(): void
    {
        $shop = $this->shop();
        Queue::fake();
        $this->fakeShopify($this->order());
        app(DisputeProcessor::class)->process($shop, '789', true);
        $delivery = AutomationDelivery::sole();
        $this->verified($shop);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public function test_queued_custom_mail_never_falls_back_after_confirmed_dns_loss(): void
    {
        [$shop, $delivery] = $this->queuedDelivery();
        $this->mock(SenderDnsVerifier::class, fn ($m) => $m->shouldReceive('matches')->andReturn(false));
        app(MerchantSenderService::class)->check($shop);
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public function test_sender_change_between_composition_and_send_refuses_old_identity(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $service = app(MerchantSenderService::class);
        $identity = $service->identity($shop);
        $service->save($shop, 'New Name', 'new@store-one.com');
        $this->expectException(EmailProviderException::class);
        $service->guard($shop, $identity, false, fn () => $this->fail('Old identity sent.'));
    }

    public function test_sender_routes_are_authenticated_csrf_protected_and_throttled(): void
    {
        $this->getJson('/settings/email-sender')->assertStatus(302);
        $shop = $this->shop();
        $this->merchant($shop)->post('/settings/email-sender/verify')->assertStatus(419);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/settings/email-sender/verify')->assertNotFound();
        }
        $this->postJson('/settings/email-sender/verify')->assertStatus(429);
    }

    public function test_uninstall_and_shop_redaction_disable_and_remove_local_sender(): void
    {
        $shop = $this->shop();
        $this->verified($shop);
        $this->webhook($shop, 'app/uninstalled')->assertOk();
        $this->assertSame('REMOVED', $shop->emailSender()->first()->verification_status);
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop->fresh()));
        $shop->delete();
        $this->assertDatabaseCount('merchant_email_senders', 0);
        $this->assertDatabaseCount('email_sending_domains', 1);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }
}
