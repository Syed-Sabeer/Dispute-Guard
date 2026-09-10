<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutomationDelivery;
use App\Models\Dispute;
use App\Models\EmailLog;
use App\Models\PrivacyRequest;
use App\Models\Shop;
use App\Models\WebhookEvent;
use App\Services\Billing\UsageQuota;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainChargeGuard extends Command
{
    protected $signature = 'chargeguard:maintain';

    protected $description = 'Minimize retained data and flag interrupted email deliveries';

    public function handle(): int
    {
        $before = now()->subDays(max(1, (int) config('chargeguard.retention_days')));
        EmailLog::where('created_at', '<', $before)->update(['rendered_body' => null, 'subject' => null, 'recipient_hash' => null, 'recipient_masked' => null]);
        Dispute::where('created_at', '<', $before)->update(['tracking_number' => null, 'tracking_url' => null, 'customer_email_hash' => null, 'order_name' => null]);
        WebhookEvent::where('received_at', '<', now()->subDays(7))->whereNotNull('payload')->update(['payload' => null, 'status' => 'EXPIRED', 'error_message' => 'Payload retention expired; review any unfinished privacy action.']);
        PrivacyRequest::where('created_at', '<', now()->subDays(30))->where('status', 'READY')->update(['export' => null, 'status' => 'EXPIRED']);
        Shop::where('status', 'INACTIVE')->where('uninstalled_at', '<', now()->subDays(2))->eachById(fn ($shop) => $shop->delete());
        AutomationDelivery::where('status', 'SENDING')->where('claimed_at', '<', now()->subMinutes(5))->eachById(function ($delivery) {
            DB::transaction(function () use ($delivery) {
                Dispute::whereKey($delivery->dispute_id)->lockForUpdate()->first();
                $delivery->refresh();
                if ($delivery->status !== 'SENDING' || $delivery->claimed_at->gte(now()->subMinutes(5))) {
                    return;
                }
                $uncertain = $delivery->transport_started_at !== null || $delivery->quota_period_id === null || $delivery->quota_status === 'CONSUMED';
                app(UsageQuota::class)->settle($delivery, $uncertain);
                $delivery->update(['status' => $uncertain ? 'UNKNOWN' : 'CANCELLED', 'failure_reason' => $uncertain ? 'Worker interrupted during email transport. Check provider before taking action.' : 'Worker interrupted before transport. Reservation released.']);
                $delivery->emailLog()->update(['status' => 'FAILED', 'error_message' => $uncertain ? 'Delivery outcome uncertain after worker interruption.' : 'Preparation interrupted before transport.']);
                $delivery->dispute->update(['automation_status' => 'MANUAL_REVIEW', 'review_reason' => $uncertain ? 'Email outcome uncertain; inspect provider logs.' : 'Preparation interrupted before transport; review manually.']);
            }, 3);
        });
        AutomationDelivery::where('quota_status', 'RESERVED')->whereIn('status', ['SENT', 'UNKNOWN', 'CANCELLED', 'FAILED'])->eachById(function ($delivery) {
            app(UsageQuota::class)->settle($delivery, in_array($delivery->status, ['SENT', 'UNKNOWN'], true));
        });
        EmailLog::where('type', 'test')->where('status', 'SENDING')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'FAILED', 'error_message' => 'Test worker interrupted; delivery outcome uncertain.']);
        $this->info('Retention and delivery recovery completed.');

        return self::SUCCESS;
    }
}
