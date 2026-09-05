<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessDisputeCreated;
use App\Jobs\ProcessPrivacyWebhook;
use App\Models\Dispute;
use App\Models\EmailLog;
use App\Models\PrivacyRequest;
use App\Models\WebhookEvent;
use App\Services\Email\Recipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookPrivacyTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    public function test_valid_signature_queues_once_invalid_signature_rejected(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->webhook($shop)->assertOk();
        $this->webhook($shop)->assertOk();
        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertPushed(ProcessDisputeCreated::class, 1);
        $this->webhook($shop, id: 'invalid', valid: false)->assertStatus(401);
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_unknown_shop_is_acknowledged_without_processing(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $shop->delete();
        $this->webhook($shop)->assertOk();
        Queue::assertNothingPushed();
        $this->assertSame('IGNORED', WebhookEvent::sole()->status);
    }

    public function test_webhook_job_is_durable_in_database_queue(): void
    {
        $shop = $this->shop();
        $this->webhook($shop)->assertOk();
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringContainsString('ProcessDisputeCreated', $payload);
        $this->assertStringNotContainsString('offline-test-token', $payload);
    }

    public function test_uninstall_revokes_token_and_master_switch(): void
    {
        $shop = $this->shop();
        $this->webhook($shop, 'app/uninstalled')->assertOk();
        $this->assertFalse($shop->fresh()->active());
        $this->assertNull($shop->fresh()->access_token);
        $this->assertFalse($shop->settings()->first()->auto_email_enabled);
    }

    public function test_privacy_export_and_customer_redaction(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $d = Dispute::factory()->create(['shop_id' => $shop->id, 'shopify_order_id' => 'gid://shopify/Order/456', 'customer_email_hash' => Recipient::hash('alex@example.com'), 'tracking_number' => 'private-tracking']);
        $log = EmailLog::factory()->create(['shop_id' => $shop->id, 'dispute_id' => $d->id, 'recipient_hash' => Recipient::hash('alex@example.com'), 'rendered_body' => '<p>Alex</p>']);
        $this->webhook($shop, 'customers/data_request', 'privacy-1', ['customer' => ['email' => 'alex@example.com'], 'orders_requested' => [456]])->assertOk();
        app()->call([new ProcessPrivacyWebhook(WebhookEvent::sole()->id), 'handle']);
        $this->assertSame('READY', PrivacyRequest::sole()->status);
        $this->assertCount(1, PrivacyRequest::sole()->export['disputes']);
        $this->webhook($shop, 'customers/redact', 'privacy-2', ['customer' => ['email' => 'alex@example.com'], 'orders_to_redact' => [456]])->assertOk();
        app()->call([new ProcessPrivacyWebhook(WebhookEvent::where('webhook_id', 'privacy-2')->sole()->id), 'handle']);
        $this->assertNull($d->fresh()->tracking_number);
        $this->assertNotNull($d->fresh()->redacted_at);
        $this->assertNull($log->fresh()->rendered_body);
        $this->assertNull(PrivacyRequest::sole()->export);
    }

    public function test_shop_redaction_removes_tenant_data(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->webhook($shop, 'app/uninstalled', 'uninstall')->assertOk();
        $this->webhook($shop, 'shop/redact', 'redact')->assertOk();
        app()->call([new ProcessPrivacyWebhook(WebhookEvent::where('webhook_id', 'redact')->sole()->id), 'handle']);
        $this->assertDatabaseMissing('shops', ['id' => $shop->id]);
        $this->assertDatabaseCount('email_templates',0);
    }
}
