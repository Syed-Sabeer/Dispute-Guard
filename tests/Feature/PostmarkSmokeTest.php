<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostmarkSmokeTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    private const ID = '12345678-1234-1234-1234-123456789abc';

    protected function setUp(): void
    {
        parent::setUp();
        app()->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'postmark', 'services.postmark.token' => 'secret-server-token',
            'services.postmark.account_token' => '', 'senders.required' => true,
            'mail.from.address' => 'notifications@app-domain.com', 'mail.from.name' => 'Dispute Guard',
            'chargeguard.test_mode' => true, 'chargeguard.test_tools' => false]);
        Http::preventStrayRequests();
    }

    public function test_one_explicit_operator_message_without_merchant_side_effects(): void
    {
        $shop = $this->shop();
        $before = $shop->settings()->first()->getAttributes();
        Http::fake(['api.postmarkapp.com/email' => Http::response(['ErrorCode' => 0, 'MessageID' => self::ID])]);
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => 'operator@own-domain.com', '--force' => true])
            ->expectsOutput('PASS Postmark accepted test message')->expectsOutput('Message ID: '.self::ID)->assertExitCode(0);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['To'] === 'operator@own-domain.com' && str_starts_with($r['Subject'], '[TEST]')
            && str_contains($r['HtmlBody'], 'Dispute Guard production email-provider smoke test')
            && str_contains($r['From'], 'notifications@app-domain.com')
            && ! isset($r['Cc']) && ! isset($r['Bcc']) && ! isset($r['Attachments'])
            && ! $r->hasHeader('X-Postmark-Account-Token'));
        foreach (['disputes', 'automation_deliveries', 'email_logs', 'merchant_email_senders'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($before, $shop->settings()->first()->getAttributes());
    }

    public static function invalidRecipients(): array
    {
        return [['bad'], ['a@own-domain.com,b@own-domain.com'], ["a@own-domain.com\r\nBcc: b@own-domain.com"], ['a@own-domain.com;b@own-domain.com']];
    }

    #[DataProvider('invalidRecipients')]
    public function test_invalid_or_multiple_recipients_are_rejected(string $recipient): void
    {
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => $recipient, '--force' => true])->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_production_confirmation_defaults_to_no(): void
    {
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => 'operator@own-domain.com'])
            ->expectsConfirmation('Send one real [TEST] email to the explicitly supplied operator recipient?', 'no')
            ->expectsOutput('Cancelled. No message sent.')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_missing_configuration_fails_without_network(): void
    {
        config(['services.postmark.token' => '']);
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => 'operator@own-domain.com', '--force' => true])
            ->expectsOutput('FAIL Configure Postmark and a valid application fallback sender.')->assertExitCode(1);
        Http::assertNothingSent();
    }

    public function test_uncertain_provider_acceptance_is_not_retried(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('secret-server-token');
        });
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => 'operator@own-domain.com', '--force' => true])
            ->expectsOutput('UNKNOWN — provider acceptance could not be determined; do not automatically retry.')->assertExitCode(1);
        $this->assertSame(1, $attempts);
    }

    public function test_rejection_output_never_exposes_raw_provider_response(): void
    {
        Http::fake(['api.postmarkapp.com/email' => Http::response(['ErrorCode' => 300, 'Message' => 'secret-server-token'], 422)]);
        $this->artisan('chargeguard:postmark-smoke-test', ['recipient' => 'operator@own-domain.com', '--force' => true])
            ->expectsOutput('FAIL Provider rejected test or is not configured.')->doesntExpectOutputToContain('secret-server-token')->assertExitCode(1);
        Http::assertSentCount(1);
    }

    public function test_production_urls_keep_scopes_client_and_webhooks(): void
    {
        $toml = file_get_contents(base_path('shopify.app.toml'));
        $this->assertStringContainsString('application_url = "https://disputeguard.deveoninc.com"', $toml);
        $this->assertStringContainsString('https://disputeguard.deveoninc.com/auth/patch-id-token', $toml);
        $this->assertStringNotContainsString('.invalid', $toml);
        $this->assertStringContainsString('client_id = "7a2b14068b84ee71382d2f27b775b5ad"', $toml);
        $this->assertStringContainsString('embedded = true', $toml);
        $this->assertStringContainsString('scopes = "read_customers,read_orders,read_shopify_payments_disputes"', $toml);
        foreach (['disputes/create', 'disputes/update', 'app/uninstalled', 'customers/data_request', 'customers/redact', 'shop/redact'] as $topic) {
            $this->assertStringContainsString('"'.$topic.'"', $toml);
        }
    }
}
