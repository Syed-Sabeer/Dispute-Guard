<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmailProviderException;
use App\Exceptions\ShopifyApiException;
use App\Models\AutomationDelivery;
use App\Models\Dispute;
use App\Models\EmailLog;
use App\Services\Disputes\AutomationResolver;
use App\Services\Email\EmailComposer;
use App\Services\Email\MerchantSenderService;
use App\Services\Email\Recipient;
use App\Services\Orders\OrderShippingStateResolver;
use App\Services\Shopify\ShopifyOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendDisputeCustomerEmail extends QueuedJob
{
    public function __construct(public int $deliveryId) {}

    public function handle(AutomationResolver $resolver, ShopifyOrderService $orders, EmailComposer $composer): void
    {
        $delivery = AutomationDelivery::find($this->deliveryId);
        if (! $delivery || $delivery->status !== 'QUEUED') {
            return;
        }
        $shop = $delivery->shop;
        $dispute = $delivery->dispute;
        if ($dispute->reason !== $delivery->dispute_reason || $dispute->shipping_state !== $delivery->shipping_state) {
            $this->cancel($delivery, 'Dispute or shipment changed while queued; manual review required.');

            return;
        }
        $template = $resolver->template($shop, $delivery->dispute_reason, $delivery->shipping_state);
        $blocked = $resolver->blocked($shop, $dispute, $template);
        if ($blocked) {
            $this->cancel($delivery, $blocked);

            return;
        }
        // Fetch the actual recipient from Shopify; no production recipient is accepted from the browser or queue.
        try {
            $order = $orders->fetch($shop, (string) $dispute->shopify_order_id);
        } catch (ShopifyApiException $e) {
            if ($e->transient) {
                throw $e;
            }
            $this->cancel($delivery, 'Shopify order access unavailable; reconnect or review permissions.');

            return;
        }
        $email = $order['email'] ?? null;
        $latestShipping = app(OrderShippingStateResolver::class)->inspect($order ?? []);
        if ($latestShipping['state']->value !== $delivery->shipping_state) {
            $this->cancel($delivery, 'Current shipment no longer matches the queued template; manual review required.');

            return;
        }
        if (! Recipient::valid($email) || ! hash_equals((string) $delivery->recipient_hash, Recipient::hash($email))) {
            $this->cancel($delivery, 'Customer recipient is unavailable or changed; manual review required.');

            return;
        }
        $variables = $composer->variables($shop, $dispute, $order);
        $tracking = $latestShipping['tracking'][0] ?? [];
        $variables = array_replace($variables, ['carrier' => $tracking['company'] ?? '', 'tracking_number' => $tracking['number'] ?? '', 'tracking_url' => $tracking['url'] ?? '', 'raw_shipment_status' => $latestShipping['raw']]);
        try {
            $message = $composer->compose($shop, $template, $variables);
        } catch (EmailProviderException $e) {
            $this->cancel($delivery, $e->getMessage());

            return;
        }
        $claimed = DB::transaction(function () use ($delivery, $shop, $dispute, $template, $email, $message) {
            $shop->refresh();
            $template->refresh();
            $lockedDispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            $settings = $shop->settings()->first();
            if (config('chargeguard.test_mode') || ! $shop->active() || ! $settings?->auto_email_enabled || $settings->test_mode || $lockedDispute->redacted_at || ! $template->enabled) {
                return false;
            }
            $claimed = AutomationDelivery::whereKey($delivery->id)->where('status', 'QUEUED')->update(['status' => 'SENDING', 'claimed_at' => now()]);
            if (! $claimed) {
                return false;
            }
            EmailLog::updateOrCreate(['automation_delivery_id' => $delivery->id], ['shop_id' => $shop->id, 'dispute_id' => $dispute->id, 'email_template_id' => $template->id,
                'type' => 'automatic', 'recipient_masked' => Recipient::mask($email), 'recipient_hash' => Recipient::hash($email),
                'subject' => $message['subject'], 'rendered_body' => $message['body'], 'shipping_state' => $delivery->shipping_state, 'dispute_reason' => $delivery->dispute_reason, 'status' => 'SENDING']);

            return true;
        });
        if (! $claimed) {
            return;
        }
        try {
            $shop->refresh();
            $dispute->refresh();
            $settings = $shop->settings()->first();
            if (config('chargeguard.test_mode') || ! $shop->active() || $dispute->redacted_at || ! $settings?->auto_email_enabled || $settings->test_mode) {
                $this->cancel($delivery, 'Automation stopped before sending.', true);

                return;
            }
            $sent = app(MerchantSenderService::class)->guard($shop, $message['identity'], false, fn () => Mail::to($email)->send($message['mailable']));
            $providerId = config('mail.default') === 'postmark' ? $sent?->getMessageId() : null;
            DB::transaction(function () use ($delivery, $dispute, $providerId) {
                $dispute = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
                if ($dispute->redacted_at) {
                    return;
                }
                $delivery->update(['status' => 'SENT', 'sent_at' => now()]);
                $delivery->emailLog()->update(['status' => 'SENT', 'sent_at' => now(), 'provider_message_id' => $providerId]);
                $dispute->update(['email_sent' => true, 'email_sent_at' => now(), 'automation_status' => 'EMAIL_SENT', 'review_reason' => null]);
            });
        } catch (\Throwable $e) {
            if ($e instanceof EmailProviderException && $e->category !== 'DELIVERY_OUTCOME_UNKNOWN') {
                $delivery->update(['status' => 'FAILED', 'failure_reason' => $e->getMessage()]);
                $delivery->emailLog()->update(['status' => 'FAILED', 'error_message' => $e->getMessage()]);
                $dispute->update(['automation_status' => 'MANUAL_REVIEW', 'review_reason' => $e->getMessage()]);

                return;
            }
            // Email provider may have accepted the email even when the client reports failure. Never automatically resend.
            $delivery->update(['status' => 'UNKNOWN', 'failure_reason' => 'Delivery outcome uncertain; review Email provider provider records before any further action.']);
            $delivery->emailLog()->update(['status' => 'FAILED', 'error_message' => 'Email provider outcome uncertain. Automatic retry suppressed.']);
            $dispute->update(['automation_status' => 'MANUAL_REVIEW', 'review_reason' => 'Email delivery outcome uncertain; check provider records.']);
        }
    }

    private function cancel(AutomationDelivery $delivery, string $reason, bool $claimed = false): void
    {
        DB::transaction(function () use ($delivery, $reason, $claimed) {
            $changed = AutomationDelivery::whereKey($delivery->id)->where('status', $claimed ? 'SENDING' : 'QUEUED')->update(['status' => 'CANCELLED', 'failure_reason' => $reason]);
            if (! $changed) {
                return;
            }
            $delivery->emailLog()->update(['status' => 'CANCELLED', 'error_message' => $reason, 'rendered_body' => null]);
            $delivery->dispute->update(['automation_status' => 'MANUAL_REVIEW', 'review_reason' => $reason]);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $delivery = AutomationDelivery::find($this->deliveryId);
        if ($delivery && $delivery->status === 'QUEUED') {
            $this->cancel($delivery, 'Email preparation failed after bounded retries.');
        }
    }
}
