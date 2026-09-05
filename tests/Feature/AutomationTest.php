<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DisputeReason;
use App\Enums\OrderShippingState;
use App\Jobs\SendDisputeCustomerEmail;
use App\Mail\DisputeCustomerMail;
use App\Models\EmailLog;
use App\Services\Disputes\AutomationResolver;
use App\Services\Disputes\DisputeProcessor;
use App\Services\Email\DefaultEmailTemplateFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase, \Tests\Fixtures\ShopifyData;

    protected function setUp(): void
    {
        parent::setUp();
        config(['chargeguard.test_mode' => false]);
    }

    public static function matrix(): array
    {
        $cases = [];
        foreach (DisputeReason::cases() as $r) {
            foreach (OrderShippingState::automatic() as $s) {
                $cases[] = [$r->value, $s->value];
            }
        }

return $cases;
    }

    #[DataProvider('matrix')]
    public function test_all_twenty_combinations_queue_correct_template(string $reason, string $state): void
    {
        Queue::fake();
        $shop = $this->shop();
        $order = $this->order($state === 'TRACKING_ADDED' ? 'CONFIRMED' : $state);
        if ($state === 'UNFULFILLED') {
            $order['displayFulfillmentStatus'] = 'UNFULFILLED';
        }
        $this->fakeShopify($order, $this->disputeData($reason));
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame($state, $d->shipping_state);
        $this->assertSame('EMAIL_QUEUED', $d->automation_status);
        $delivery = $d->automationDeliveries()->sole();
        $this->assertSame($reason, $delivery->template->dispute_reason);
        $this->assertSame($state, $delivery->template->shipping_state);
        Queue::assertPushed(SendDisputeCustomerEmail::class, 1);
    }

    public function test_template_seeding_is_idempotent_and_preserves_edits(): void
    {
        $shop = $this->shop();
        $template = $shop->emailTemplates()->first();
        $template->update(['subject' => 'Merchant edit']);
        app(DefaultEmailTemplateFactory::class)->seed($shop);
        $this->assertSame(20, $shop->emailTemplates()->count());
        $this->assertSame('Merchant edit', $template->fresh()->subject);
    }

    public function test_unsupported_reason_unknown_shipping_and_disabled_template_never_queue(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $resolver = app(AutomationResolver::class);
        $this->assertNull($resolver->template($shop, 'OTHER', 'DELIVERED'));
        $this->assertNull($resolver->template($shop, 'FRAUDULENT', 'UNKNOWN'));
        $shop->emailTemplates()->update(['enabled' => false]);
        $this->assertNull($resolver->template($shop, 'FRAUDULENT', 'IN_TRANSIT'));
        $this->fakeShopify($this->order(), $this->disputeData('OTHER'));
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame('OTHER', $d->reason);
        $this->assertSame('MANUAL_REVIEW', $d->automation_status);
        Queue::assertNothingPushed();
    }

    public function test_sends_actual_customer_once_and_updates_do_not_resend(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        $processor = app(DisputeProcessor::class);
        $d = $processor->process($shop, '789', true);
        $delivery = $d->automationDeliveries()->sole();
        $job = new SendDisputeCustomerEmail($delivery->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $processor->process($shop, '789', true);
        $processor->process($shop, '789', false);
        Mail::assertSent(DisputeCustomerMail::class, fn ($mail) => $mail->hasTo('actual-customer@example.com'));
        Mail::assertSentCount(1);
        Queue::assertPushed(SendDisputeCustomerEmail::class, 1);
        $this->assertTrue($d->fresh()->email_sent);
        $this->assertSame('SENT', $delivery->fresh()->status);
        $this->assertSame('a***@example.com', EmailLog::sole()->recipient_masked);
    }

    public function test_uninstalled_shop_cannot_send_queued_email(): void
    {
        Queue::fake();
        Mail::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $shop->update(['status' => 'INACTIVE', 'uninstalled_at' => now(), 'access_token' => null]);
        app()->call([new SendDisputeCustomerEmail($d->automationDeliveries()->sole()->id), 'handle']);
        Mail::assertNothingSent();
        $this->assertSame('CANCELLED', $d->automationDeliveries()->sole()->status);
    }

    public function test_master_switch_test_mode_and_missing_recipient_block(): void
    {
        Queue::fake();
        $shop = $this->shop(['auto_email_enabled' => false]);
        $this->fakeShopify($this->order());
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        $this->assertSame('MANUAL_REVIEW', $d->automation_status);
        Queue::assertNothingPushed();
        $shop->settings->update(['auto_email_enabled' => true]);
        config(['chargeguard.test_mode' => true]);
        $d = app(DisputeProcessor::class)->process($shop, '790', true);
        $this->assertSame('MANUAL_REVIEW', $d->automation_status);
        config(['chargeguard.test_mode' => false]);
        $order = $this->order();
        $order['email'] = null;
        $this->fakeShopify($order);
        $d = app(DisputeProcessor::class)->process($shop, '791', true);
        $this->assertStringContainsString('Customer email unavailable', $d->review_reason);
        Queue::assertNothingPushed();
    }

    public function test_smtp_uncertainty_is_never_automatically_retried(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeShopify($this->order());
        $d = app(DisputeProcessor::class)->process($shop, '789', true);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Sensitive SMTP diagnostics'));
        $job = new SendDisputeCustomerEmail($d->automationDeliveries()->sole()->id);
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame('UNKNOWN',$d->automationDeliveries()->sole()->status);
        $this->assertStringNotContainsString('Sensitive',EmailLog::sole()->error_message);
    }
}
