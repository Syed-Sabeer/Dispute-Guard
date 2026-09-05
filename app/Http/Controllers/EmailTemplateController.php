<?php
declare(strict_types=1);
namespace App\Http\Controllers;
use App\Models\EmailTemplate;
use App\Http\Requests\UpdateEmailTemplateRequest;
use App\Services\Email\{DefaultEmailTemplateFactory,TemplateRenderer};
use App\Enums\{DisputeReason,OrderShippingState};
use Illuminate\Http\Request;
class EmailTemplateController extends MerchantController
{
    public function index() { return $this->page('templates.index',['groups'=>$this->shop()->emailTemplates()->get()->groupBy('dispute_reason')]); }
    public function edit(EmailTemplate $template) { $this->owned($template); return $this->page('templates.edit',['template'=>$template]); }
    public function update(UpdateEmailTemplateRequest $request, EmailTemplate $template, TemplateRenderer $renderer)
    {
        $this->owned($template); $data = $request->validated(); $data['body'] = $renderer->sanitize($data['body']);
        $template->update($data + ['is_default_modified'=>true]); return response()->json(['message'=>'Template saved.']);
    }
    public function restore(Request $request, EmailTemplate $template, DefaultEmailTemplateFactory $factory)
    {
        $this->owned($template); abort_unless($request->boolean('confirmed'),422,'Confirm restoration first.');
        $template->update($factory->make(DisputeReason::from($template->dispute_reason),OrderShippingState::from($template->shipping_state))+['is_default_modified'=>false]);
        return response()->json(['message'=>'Default restored.','reload'=>true]);
    }
    public function preview(UpdateEmailTemplateRequest $request, EmailTemplate $template, TemplateRenderer $renderer)
    {
        $this->owned($template);
        $variables = ['customer_name'=>'Sample customer','order_number'=>'#TEST-1001','order_amount'=>'49.00','currency'=>'USD','store_name'=>$this->shop()->store_name,'support_email'=>$this->shop()->settings->support_email,'shipment_status'=>$template->shipping_state,'dispute_reason'=>$template->dispute_reason];
        return response()->json(['subject'=>$renderer->render($request->input('subject'),$variables,false),'html'=>$renderer->render($request->input('body'),$variables)]);
    }
}
