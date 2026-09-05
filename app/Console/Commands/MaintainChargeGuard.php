<?php
declare(strict_types=1);
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\{EmailLog,WebhookEvent,PrivacyRequest,AutomationDelivery,Shop};
class MaintainChargeGuard extends Command
{
    protected $signature = 'chargeguard:maintain';
    protected $description = 'Minimize retained data and flag interrupted email deliveries';
    public function handle(): int
    {
        $before = now()->subDays(max(1,(int) config('chargeguard.retention_days')));
        EmailLog::where('created_at','<',$before)->update(['rendered_body'=>null,'subject'=>null,'recipient_hash'=>null,'recipient_masked'=>null]);
        \App\Models\Dispute::where('created_at','<',$before)->update(['tracking_number'=>null,'tracking_url'=>null,'customer_email_hash'=>null,'order_name'=>null]);
        WebhookEvent::where('received_at','<',now()->subDays(7))->whereNotNull('payload')->update(['payload'=>null,'status'=>'EXPIRED','error_message'=>'Payload retention expired; review any unfinished privacy action.']);
        PrivacyRequest::where('created_at','<',now()->subDays(30))->where('status','READY')->update(['export'=>null,'status'=>'EXPIRED']);
        Shop::where('status','INACTIVE')->where('uninstalled_at','<',now()->subDays(2))->eachById(fn ($shop) => $shop->delete());
        AutomationDelivery::where('status','SENDING')->where('claimed_at','<',now()->subMinutes(5))->eachById(function ($delivery) {
            $delivery->update(['status'=>'UNKNOWN','failure_reason'=>'Worker interrupted during SMTP send. Check provider before taking action.']);
            $delivery->emailLog()->update(['status'=>'FAILED','error_message'=>'Delivery outcome uncertain after worker interruption.']);
            $delivery->dispute->update(['automation_status'=>'MANUAL_REVIEW','review_reason'=>'Email outcome uncertain; inspect provider logs.']);
        });
        EmailLog::where('type','test')->where('status','SENDING')->where('updated_at','<',now()->subMinutes(5))->update(['status'=>'FAILED','error_message'=>'Test worker interrupted; delivery outcome uncertain.']);
        $this->info('Retention and delivery recovery completed.'); return self::SUCCESS;
    }
}
