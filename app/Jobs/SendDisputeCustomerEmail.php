<?php
declare(strict_types=1);
namespace App\Jobs;
use App\Models\{AutomationDelivery,EmailLog};
use App\Services\Disputes\AutomationResolver;
use App\Services\Email\{EmailComposer,Recipient};
use App\Services\Shopify\ShopifyOrderService;
use Illuminate\Support\Facades\{DB,Mail};
class SendDisputeCustomerEmail extends QueuedJob
{
    public function __construct(public int $deliveryId) {}
    public function handle(AutomationResolver $resolver, ShopifyOrderService $orders, EmailComposer $composer): void
    {
        $delivery = AutomationDelivery::find($this->deliveryId);
        if (!$delivery || $delivery->status !== 'QUEUED') { return; }
        $shop = $delivery->shop; $dispute = $delivery->dispute;
        $template = $resolver->template($shop, $delivery->dispute_reason, $delivery->shipping_state);
        $blocked = $resolver->blocked($shop, $dispute, $template);
        if ($blocked) { $this->cancel($delivery, $blocked); return; }
        // Fetch the actual recipient from Shopify; no production recipient is accepted from the browser or queue.
        $order = $orders->fetch($shop, (string) $dispute->shopify_order_id);
        $email = $order['email'] ?? null;
        if (!Recipient::valid($email) || !hash_equals((string) $delivery->recipient_hash, Recipient::hash($email))) {
            $this->cancel($delivery, 'Customer recipient is unavailable or changed; manual review required.'); return;
        }
        $message = $composer->compose($shop, $template, $composer->variables($shop, $dispute, $order));
        $claimed = DB::transaction(function () use ($delivery,$shop,$dispute,$template,$email,$message) {
            $shop->refresh(); $dispute->refresh(); $template->refresh();
            if (!$shop->active() || !$shop->settings()->first()?->auto_email_enabled || $dispute->redacted_at || !$template->enabled) { return false; }
            $claimed = AutomationDelivery::whereKey($delivery->id)->where('status','QUEUED')->update(['status'=>'SENDING','claimed_at'=>now()]);
            if (!$claimed) { return false; }
            EmailLog::create(['shop_id'=>$shop->id,'dispute_id'=>$dispute->id,'email_template_id'=>$template->id,'automation_delivery_id'=>$delivery->id,
                'type'=>'automatic','recipient_masked'=>Recipient::mask($email),'recipient_hash'=>Recipient::hash($email),
                'subject'=>$message['subject'],'rendered_body'=>$message['body'],'shipping_state'=>$delivery->shipping_state,'dispute_reason'=>$delivery->dispute_reason,'status'=>'SENDING']);
            return true;
        });
        if (!$claimed) { return; }
        try {
            $shop->refresh(); $dispute->refresh();
            if (!$shop->active() || $dispute->redacted_at || !$shop->settings()->first()?->auto_email_enabled) { $this->cancel($delivery, 'Automation stopped before sending.'); return; }
            Mail::to($email)->send($message['mailable']);
            DB::transaction(function () use ($delivery,$dispute) {
                $delivery->update(['status'=>'SENT','sent_at'=>now()]);
                $delivery->emailLog()->update(['status'=>'SENT','sent_at'=>now()]);
                $dispute->update(['email_sent'=>true,'email_sent_at'=>now(),'automation_status'=>'EMAIL_SENT','review_reason'=>null]);
            });
        } catch (\Throwable) {
            // SMTP may have accepted the email even when the client reports failure. Never automatically resend.
            $delivery->update(['status'=>'UNKNOWN','failure_reason'=>'Delivery outcome uncertain; review SMTP provider records before any further action.']);
            $delivery->emailLog()->update(['status'=>'FAILED','error_message'=>'SMTP outcome uncertain. Automatic retry suppressed.']);
            $dispute->update(['automation_status'=>'MANUAL_REVIEW','review_reason'=>'Email delivery outcome uncertain; check provider records.']);
        }
    }
    private function cancel(AutomationDelivery $delivery, string $reason): void
    {
        $delivery->update(['status'=>'CANCELLED','failure_reason'=>$reason]);
        $delivery->emailLog()->update(['status'=>'CANCELLED','error_message'=>$reason,'rendered_body'=>null]);
        $delivery->dispute->update(['automation_status'=>'MANUAL_REVIEW','review_reason'=>$reason]);
    }
    public function failed(?\Throwable $exception): void
    {
        $delivery = AutomationDelivery::find($this->deliveryId);
        if ($delivery && $delivery->status === 'QUEUED') { $this->cancel($delivery, 'Email preparation failed after bounded retries.'); }
    }
}
