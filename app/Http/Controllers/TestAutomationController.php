<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Http\Requests\SendTestAutomationRequest;
use App\Jobs\SendTestAutomationEmail;
use App\Services\Email\Recipient;
use Illuminate\Support\Facades\{Crypt,DB};
class TestAutomationController extends MerchantController
{
    public function index() { return $this->page('test-automation.index'); }
    public function send(SendTestAutomationRequest $request)
    {
        $shop = $this->shop(); $data = $request->validated();
        $template = $shop->emailTemplates()->where('dispute_reason',$data['dispute_reason'])->where('shipping_state',$data['shipment_status'])->firstOrFail();
        $data += ['support_email'=>$shop->settings->support_email,'dispute_status'=>'NEEDS_RESPONSE','dispute_amount'=>$data['order_amount'],'order_date'=>now()->toDateString(),'dispute_date'=>now()->toDateString(),'raw_shipment_status'=>$data['shipment_status']];
        DB::transaction(function () use ($shop,$data,$template) {
            $log = $shop->emailLogs()->create(['email_template_id'=>$template->id,'type'=>'test','recipient_masked'=>Recipient::mask($data['customer_email']),
                'recipient_hash'=>Recipient::hash($data['customer_email']),'shipping_state'=>$template->shipping_state,'dispute_reason'=>$template->dispute_reason]);
            SendTestAutomationEmail::dispatch($log->id,Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR)))->onConnection('database');
        });
        return response()->json(['message'=>'TEST email queued. Check Email Logs after the queue worker runs.']);
    }
}
