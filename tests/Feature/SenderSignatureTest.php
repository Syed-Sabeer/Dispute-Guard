<?php

namespace Tests\Feature;

use App\Exceptions\EmailProviderException;
use App\Jobs\SendDisputeCustomerEmail;
use App\Jobs\SendTestAutomationEmail;
use App\Models\AutomationDelivery;
use App\Models\EmailLog;
use App\Services\Billing\UsageQuota;
use App\Services\Disputes\DisputeProcessor;
use App\Services\Email\EmailComposer;
use App\Services\Email\MerchantSenderService;
use App\Services\Email\PostmarkEmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SenderSignatureTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    private bool $confirmed = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'postmark', 'services.postmark.token' => 'secret-server',
            'services.postmark.account_token' => 'secret-account', 'chargeguard.test_mode' => false,
            'senders.managed_address' => 'verification@system-mail.com', 'senders.managed_domain' => 'system-mail.com',
            'app.url' => 'https://app.disputeguard.com']);
        Http::preventStrayRequests();
        $this->fakeSignatures();
        Queue::fake();
    }

    private function fakeSignatures(): void
    {
        Http::fake(['api.postmarkapp.com/*' => function ($request) {
            if (str_ends_with($request->url(), '/email')) {
                return Http::response(['ErrorCode' => 0, 'MessageID' => '12345678-1234-1234-1234-123456789abc']);
            }
            if (str_ends_with($request->url(), '/resend')) {
                return Http::response(['ErrorCode' => 0]);
            }

            return Http::response(['ID' => 102, 'EmailAddress' => $request['FromEmail'] ?? 'support@merchant-business.com', 'Confirmed' => $this->confirmed]);
        }]);
    }

    private function save($shop)
    {
        return app(MerchantSenderService::class)->save($shop, 'Merchant', 'support@merchant-business.com');
    }

    private function mailboxToken($shop): string
    {
        app(MerchantSenderService::class)->sendMailboxVerification($shop);
        $message = Http::recorded(fn ($r) => str_ends_with($r->url(), '/email'))->last()[0];
        preg_match('/sender-verification#([a-f0-9]{64})/', $message['TextBody'], $matches);
        $this->assertCount(2, $matches);
        $this->assertSame('support@merchant-business.com', $message['To']);
        $this->assertStringContainsString($shop->shop_domain, $message['TextBody']);

        return $matches[1];
    }

    private function verify($shop): void
    {
        $this->save($shop);
        $token = $this->mailboxToken($shop);
        $this->assertTrue(app(MerchantSenderService::class)->confirmMailbox($token));
        $this->confirmed = true;
        $this->assertSame('VERIFIED', app(MerchantSenderService::class)->check($shop)->verification_status);
    }

    private function sampleInput(): array
    {
        return ['dispute_reason' => 'FRAUDULENT', 'shipment_status' => 'DELIVERED', 'customer_name' => 'Tester',
            'customer_email' => 'developer@example.com', 'order_number' => '#TEST', 'order_amount' => '49.00', 'currency' => 'USD', 'store_name' => 'Sample'];
    }

    public function test_save_creates_pending_signature_with_account_token_and_no_dns_or_customer_email(): void
    {
        $shop = $this->shop([], false);
        $this->merchant($shop)->putJson('/settings/email-sender', ['sender_name' => 'Merchant', 'sender_email' => 'Support@Merchant-Business.com'])->assertOk();
        $sender = $shop->emailSender()->sole();
        $this->assertSame('SIGNATURE', $sender->sender_mode);
        $this->assertSame(102, $sender->provider_signature_id);
        $this->assertSame('PENDING', $sender->verification_status);
        $this->assertFalse($sender->ownership_verified);
        $this->assertFalse(app(MerchantSenderService::class)->eligible($shop));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.postmarkapp.com/senders'
            && $r['FromEmail'] === 'support@merchant-business.com' && $r->hasHeader('X-Postmark-Account-Token', 'secret-account')
            && ! $r->hasHeader('X-Postmark-Server-Token'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/domains') || str_ends_with($r->url(), '/email'));
        $this->get('/settings/email-sender')->assertOk()->assertSee('No DNS setup is required')->assertSee('Send verification email')
            ->assertDontSee('secret-account')->assertDontSee('secret-server');
    }

    public static function blockedStates(): array
    {
        return [['missing'], ['pending'], ['ownership'], ['unconfirmed'], ['invalid_name'], ['invalid_id'], ['removed'], ['expired']];
    }

    #[DataProvider('blockedStates')]
    public function test_unverified_sender_blocks_live_test_activation_and_dashboard_without_provider_send(string $state): void
    {
        $shop = $this->shop([], $state !== 'missing');
        $values = match ($state) {
            'pending' => ['verification_status' => 'PENDING'],
            'ownership' => ['ownership_verified' => false],
            'unconfirmed' => ['provider_confirmed_at' => null],
            'invalid_name' => ['sender_name' => "Store\r\nInjected"],
            'invalid_id' => ['provider_signature_id' => 0],
            'removed' => ['verification_status' => 'REMOVED'],
            'expired' => ['last_checked_at' => now()->subMinutes(16)],
            default => [],
        };
        if ($values) {
            $shop->emailSender()->update($values);
        }
        $this->fakeShopify($this->order());
        $dispute = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame('MANUAL_REVIEW', $dispute->automation_status);
        $this->assertStringContainsString('Verify your sender email', $dispute->review_reason);
        $this->assertDatabaseCount('automation_deliveries', 0);
        $this->merchant($shop)->postJson('/test-automation/send', $this->sampleInput())->assertUnprocessable()
            ->assertJsonValidationErrors('sender_email')->assertSee('Verify your sender email before sending a test automation email.');
        $this->putJson('/settings', ['store_display_name' => 'Store', 'support_email' => 'support@merchant-business.com',
            'auto_email_enabled' => true, 'test_mode' => false, 'timezone' => 'UTC', 'templates_reviewed' => true])
            ->assertUnprocessable()->assertSee('Verify your sender email before activating automatic customer follow-ups.');
        $this->get('/dashboard')->assertOk()->assertSee('Automation paused')->assertDontSee('>Enabled</s-badge>', false);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public function test_mailbox_proof_is_hashed_one_time_public_and_bound_to_current_shop(): void
    {
        $a = $this->shop([], false);
        $this->save($a);
        $token = $this->mailboxToken($a);
        $sender = $a->emailSender()->sole();
        $this->assertSame(hash('sha256', $token), $sender->verification_token_hash);
        $this->assertStringNotContainsString($token, json_encode($sender->getAttributes()));
        $this->assertStringNotContainsString($sender->verification_token_hash, $sender->toJson());
        $this->get('/sender-verification')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/sender-verification', ['token' => $token])->assertOk()->assertSee('Mailbox ownership confirmed');
        $this->assertTrue($sender->fresh()->ownership_verified);
        $this->assertNull($sender->fresh()->verification_token_hash);
        $this->post('/sender-verification', ['token' => $token])->assertUnprocessable();
        $this->assertSame('PENDING', app(MerchantSenderService::class)->check($a)->verification_status);
        $this->confirmed = true;
        $this->assertSame('VERIFIED', app(MerchantSenderService::class)->check($a)->verification_status);
        $b = $this->shop([], false);
        $this->save($b);
        $this->assertSame('PENDING', app(MerchantSenderService::class)->check($b)->verification_status);
        $this->assertFalse($b->emailSender()->sole()->ownership_verified);
        $this->assertFalse(app(MerchantSenderService::class)->confirmMailbox($token));
        $newToken = $this->mailboxToken($b);
        $this->assertTrue(app(MerchantSenderService::class)->confirmMailbox($newToken));
        $this->assertSame('VERIFIED', app(MerchantSenderService::class)->check($b)->verification_status);
    }

    public static function invalidTokens(): array
    {
        return [['expired'], ['wrong'], ['email_changed'], ['mode_changed'], ['uninstalled'], ['disconnected']];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalidated_mailbox_tokens_fail_safely(string $case): void
    {
        $shop = $this->shop([], false);
        $this->save($shop);
        $token = $this->mailboxToken($shop);
        $service = app(MerchantSenderService::class);
        switch ($case) {
            case 'expired': $this->travel(16)->minutes();
                break;
            case 'wrong': $token = str_repeat('a', 64);
                break;
            case 'email_changed': $service->save($shop, 'Store', 'other@merchant-business.com');
                break;
            case 'mode_changed': $shop->emailSender()->update(['sender_mode' => 'DOMAIN']);
                break;
            case 'uninstalled': $this->webhook($shop, 'app/uninstalled')->assertOk();
                break;
            case 'disconnected': $service->disconnect($shop);
                break;
        }
        $this->post('/sender-verification', ['token' => $token])->assertUnprocessable()->assertDontSee($token);
        $this->assertFalse($shop->emailSender()->sole()->ownership_verified);
    }

    public function test_resend_is_rate_limited_and_replaces_previous_token(): void
    {
        $shop = $this->shop([], false);
        $this->save($shop);
        $old = $this->mailboxToken($shop);
        $this->merchant($shop)->postJson('/settings/email-sender/resend')->assertStatus(429);
        $this->travel(61)->seconds();
        $new = $this->mailboxToken($shop);
        $service = app(MerchantSenderService::class);
        $this->assertFalse($service->confirmMailbox($old));
        $this->assertTrue($service->confirmMailbox($new));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/senders/102/resend') && $r->hasHeader('X-Postmark-Account-Token', 'secret-account'));
    }

    public static function replyAddresses(): array
    {
        return [['reply@merchant-business.com', 'help@merchant-business.com', 'reply@merchant-business.com'],
            [null, 'help@merchant-business.com', 'help@merchant-business.com'], [null, null, 'support@merchant-business.com']];
    }

    #[DataProvider('replyAddresses')]
    public function test_both_mailables_use_exact_merchant_from_and_reply_precedence(?string $reply, ?string $support, string $expected): void
    {
        $shop = $this->shop(['store_display_name' => 'ABC Fashion', 'reply_to_email' => $reply, 'support_email' => $support], false);
        $this->verify($shop);
        foreach ([false, true] as $test) {
            $message = app(EmailComposer::class)->compose($shop, $shop->emailTemplates()->first(), [], $test);
            $mail = $message['mailable']->build();
            $this->assertTrue($mail->hasFrom('support@merchant-business.com', 'ABC Fashion'));
            $this->assertTrue($mail->hasReplyTo($expected));
            $this->assertSame($test, str_starts_with($message['subject'], '[TEST]'));
            $this->assertFalse($mail->hasFrom('verification@system-mail.com'));
        }
    }

    public function test_live_and_test_transport_use_merchant_sender_and_never_duplicate(): void
    {
        $shop = $this->shop(['store_display_name' => 'ABC Fashion'], false);
        $this->verify($shop);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeSignatures();
        $this->fakeShopify($this->order());
        app(DisputeProcessor::class)->process($shop, '789', true);
        $delivery = AutomationDelivery::sole();
        $job = new SendDisputeCustomerEmail($delivery->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame('CONSUMED', $delivery->fresh()->quota_status);
        $this->merchant($shop)->postJson('/test-automation/send', $this->sampleInput())->assertOk();
        $test = Queue::pushed(SendTestAutomationEmail::class)->first();
        app()->call([$test, 'handle']);
        app()->call([$test, 'handle']);
        $this->assertSame('SENT', EmailLog::where('type', 'test')->sole()->status);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('"ABC Fashion" <support@merchant-business.com>', $request['From']);
            $this->assertTrue($request->hasHeader('X-Postmark-Server-Token', 'secret-server'));
            $this->assertFalse($request->hasHeader('X-Postmark-Account-Token'));
        }
    }

    public function test_signature_loss_cancels_live_reservation_and_queued_test_without_switching(): void
    {
        $shop = $this->shop([], false);
        $this->verify($shop);
        $this->fakeShopify($this->order());
        app(DisputeProcessor::class)->process($shop, '789', true);
        $delivery = AutomationDelivery::sole();
        $this->merchant($shop)->postJson('/test-automation/send', $this->sampleInput())->assertOk();
        $test = Queue::pushed(SendTestAutomationEmail::class)->first();
        $this->confirmed = false;
        app(MerchantSenderService::class)->check($shop);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        app()->call([new SendDisputeCustomerEmail($delivery->id), 'handle']);
        app()->call([$test, 'handle']);
        $this->assertSame('CANCELLED', $delivery->fresh()->status);
        $this->assertSame('RELEASED', $delivery->fresh()->quota_status);
        $this->assertSame(1000, app(UsageQuota::class)->summary($shop)['remaining']);
        $this->assertSame('FAILED', EmailLog::where('type', 'test')->sole()->status);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        Http::assertNothingSent();
    }

    public function test_test_queue_cannot_switch_to_newly_verified_sender_revision(): void
    {
        $shop = $this->shop([], false);
        $this->verify($shop);
        $this->merchant($shop)->postJson('/test-automation/send', $this->sampleInput())->assertOk();
        $test = Queue::pushed(SendTestAutomationEmail::class)->first();
        $oldRevision = $shop->emailSender()->sole()->revision;
        $sender = app(MerchantSenderService::class)->save($shop, 'Changed name', 'other@merchant-business.com');
        $this->assertSame($oldRevision + 1, $sender->revision);
        $this->assertFalse($sender->ownership_verified);
        $this->assertNull($sender->provider_confirmed_at);
        $sender->update(['ownership_verified' => true, 'provider_confirmed_at' => now(), 'verified_at' => now(), 'verification_status' => 'VERIFIED', 'last_checked_at' => now()]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        app()->call([$test, 'handle']);
        $this->assertSame('CANCELLED', EmailLog::sole()->status);
        Http::assertNothingSent();
    }

    public function test_existing_confirmed_provider_signature_requires_fresh_shop_proof(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/*' => Http::sequence()->push(['ErrorCode' => 504], 422)
            ->push(['TotalCount' => 1, 'SenderSignatures' => [['ID' => 102, 'EmailAddress' => 'support@merchant-business.com']]])
            ->push(['ID' => 102, 'EmailAddress' => 'support@merchant-business.com', 'Confirmed' => true])]);
        $shop = $this->shop([], false);
        $sender = $this->save($shop);
        $this->assertFalse($sender->ownership_verified);
        $this->assertSame('PENDING', $sender->verification_status);
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop));
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }

    public static function badResponses(): array
    {
        return [
            [['ID' => 102, 'EmailAddress' => 'someone@other-business.com', 'Confirmed' => true]],
            [['ID' => '102', 'EmailAddress' => 'support@merchant-business.com', 'Confirmed' => true]],
            [['ID' => 0, 'EmailAddress' => 'support@merchant-business.com', 'Confirmed' => true]],
            [['ID' => 103, 'EmailAddress' => 'support@merchant-business.com', 'Confirmed' => true]],
            [['ID' => 102, 'EmailAddress' => 'support@merchant-business.com', 'Confirmed' => 'true']],
        ];
    }

    #[DataProvider('badResponses')]
    public function test_provider_identity_mismatch_fails_closed(array $body): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/*' => Http::response($body)]);
        $this->expectException(EmailProviderException::class);
        app(PostmarkEmailProvider::class)->getSenderSignature(102, 'support@merchant-business.com');
    }

    public static function providerFailures(): array
    {
        return [['timeout'], ['error'], ['redirect']];
    }

    public function test_reconciliation_is_bounded_and_never_matches_a_different_mailbox(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.postmarkapp.com/senders*' => Http::response(['TotalCount' => 100000,
            'SenderSignatures' => [['ID' => 102, 'EmailAddress' => 'other@merchant-business.com', 'Confirmed' => true]]])]);
        try {
            app(PostmarkEmailProvider::class)->findSenderSignatureByEmail('support@merchant-business.com');
            $this->fail('Different mailbox accepted');
        } catch (EmailProviderException $e) {
            $this->assertSame('SENDER_NOT_VERIFIED', $e->category);
        }
        Http::assertSentCount(10);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_browser_cannot_supply_signature_id_or_shop_ownership(): void
    {
        $shop = $this->shop([], false);
        $this->merchant($shop)->putJson('/settings/email-sender', ['sender_name' => 'Merchant',
            'sender_email' => 'support@merchant-business.com', 'provider_signature_id' => 102, 'shop_id' => 99, 'verification_status' => 'VERIFIED'])
            ->assertUnprocessable()->assertJsonValidationErrors(['provider_signature_id', 'shop_id', 'verification_status']);
        Http::assertNothingSent();
    }

    public function test_application_addresses_never_become_merchant_from_even_with_persisted_verification(): void
    {
        $shop = $this->shop();
        config(['senders.managed_address' => 'support@fixture-merchant.com', 'senders.managed_domain' => 'fixture-merchant.com']);
        $this->assertSame('support@fixture-merchant.com', app(MerchantSenderService::class)->systemIdentity()['email']);
        $this->assertFalse(app(MerchantSenderService::class)->eligible($shop));
        $this->merchant($shop)->postJson('/test-automation/send', $this->sampleInput())->assertUnprocessable();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_standard_onboarding_requires_verification_but_never_dns(): void
    {
        $shop = $this->shop(['auto_email_enabled' => false, 'onboarded_at' => null], false);
        $data = ['store_display_name' => 'Store', 'support_email' => 'support@merchant-business.com',
            'auto_email_enabled' => false, 'test_mode' => true, 'timezone' => 'UTC', 'templates_reviewed' => true];
        $this->merchant($shop)->putJson('/settings', $data)->assertOk();
        $this->assertNull($shop->settings()->sole()->onboarded_at);
        $this->verify($shop);
        $this->putJson('/settings', $data)->assertOk();
        $this->assertNotNull($shop->settings()->sole()->onboarded_at);
        $this->assertFalse($shop->settings()->sole()->auto_email_enabled);
        $this->get('/settings/email-sender')->assertOk()->assertSee('No DNS setup is required')->assertDontSee('Authenticate your sending domain');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/domains'));
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_invalidates_confirmation_without_leaking_secrets(string $failure): void
    {
        $shop = $this->shop([], false);
        $this->verify($shop);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => $failure === 'timeout' ? throw new ConnectionException('secret-account')
            : Http::response(['Message' => 'secret-account private@mail.com'], $failure === 'redirect' ? 302 : 500, ['Location' => 'https://untrusted.example']));
        try {
            app(MerchantSenderService::class)->check($shop);
            $this->fail('Provider failure accepted');
        } catch (EmailProviderException $e) {
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('secret-account', $e->getMessage());
            $this->assertStringNotContainsString('private@mail.com', $e->getMessage());
        }
        $this->assertFalse(app(MerchantSenderService::class)->ready($shop));
        $this->assertNull($shop->emailSender()->sole()->provider_confirmed_at);
        $this->assertTrue($shop->settings()->first()->auto_email_enabled);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/email'));
    }
}
