<?php

declare(strict_types=1);

namespace App\Services\Privacy;

use App\Models\Dispute;
use App\Models\EmailLog;
use App\Models\PrivacyRequest;
use App\Models\WebhookEvent;
use App\Services\Billing\UsageQuota;
use App\Services\Email\Recipient;
use Illuminate\Support\Facades\DB;

class PrivacyService
{
    public function process(WebhookEvent $event): void
    {
        $shop = $event->shop;
        if (! $shop) {
            return;
        }
        $payload = $event->payload ?? [];
        if ($event->topic === 'shop/redact') {
            // Ignore delayed redaction of a previous installation after a verified reinstall.
            if ($shop->active()) {
                $event->update(['status' => 'PROCESSED', 'payload' => null, 'processed_at' => now()]);

                return;
            }
            $shop->delete();

            return;
        }
        $email = data_get($payload, 'customer.email');
        $hash = Recipient::valid($email) ? Recipient::hash($email) : null;
        $ids = array_map(fn ($id) => 'gid://shopify/Order/'.$id, $payload['orders_to_redact'] ?? $payload['orders_requested'] ?? []);
        $query = Dispute::forShop($shop)->where(function ($q) use ($ids, $hash) {
            $q->whereIn('shopify_order_id', $ids);
            if ($hash) {
                $q->orWhere('customer_email_hash', $hash);
            }
        });
        DB::transaction(function () use ($event, $shop, $query, $hash) {
            $disputes = $query->lockForUpdate()->get();
            $logs = EmailLog::forShop($shop)->where(function ($q) use ($disputes, $hash) {
                $q->whereIn('dispute_id', $disputes->modelKeys());
                if ($hash) {
                    $q->orWhere('recipient_hash', $hash);
                }
            });
            if ($event->topic === 'customers/data_request') {
                PrivacyRequest::firstOrCreate(['webhook_id' => $event->webhook_id], ['shop_id' => $shop->id,
                    'export' => ['disputes' => $disputes->toArray(), 'email_logs' => $logs->get()->toArray()],
                ]);
            } else {
                foreach ($disputes as $dispute) {
                    $dispute->automationDeliveries()->get()->each(function ($delivery) {
                        app(UsageQuota::class)->settle($delivery, $delivery->transport_started_at !== null || in_array($delivery->status, ['SENT', 'UNKNOWN'], true));
                    });
                    $dispute->automationDeliveries()->update(['status' => 'CANCELLED', 'recipient_hash' => null, 'failure_reason' => 'Customer data redacted.']);
                    $dispute->update(['redacted_at' => now(), 'order_name' => null, 'shopify_order_id' => null, 'tracking_company' => null, 'tracking_number' => null, 'tracking_url' => null, 'customer_email_hash' => null, 'refunds' => null, 'automation_status' => 'MANUAL_REVIEW', 'review_reason' => 'Customer data redacted.']);
                }
                $logs->update(['recipient_masked' => null, 'recipient_hash' => null, 'subject' => null, 'rendered_body' => null, 'status' => 'REDACTED']);
                // Exports can contain matching data; invalidate the shop's pending exports after redaction.
                PrivacyRequest::forShop($shop)->update(['export' => null, 'status' => 'REDACTED', 'completed_at' => now()]);
            }
            $event->update(['status' => 'PROCESSED', 'payload' => null, 'processed_at' => now()]);
        }, 3);
    }
}
