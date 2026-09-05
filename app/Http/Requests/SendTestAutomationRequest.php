<?php
declare(strict_types=1);
namespace App\Http\Requests;
use App\Enums\{DisputeReason,OrderShippingState};
use Illuminate\Validation\Rule;
class SendTestAutomationRequest extends MerchantRequest
{
    public function rules(): array
    {
        return [
            'dispute_reason'=>['required',Rule::enum(DisputeReason::class)],
            'shipment_status'=>['required',Rule::in(array_map(fn ($s)=>$s->value,OrderShippingState::automatic()))],
            'customer_name'=>['required','string','max:100'],'customer_email'=>$this->emailRules(true),
            'order_number'=>['required','string','max:100'],'order_amount'=>['required','regex:/^\d{1,12}(\.\d{1,4})?$/D'],
            'currency'=>['required','regex:/^[A-Z]{3}$/D'],'carrier'=>['nullable','string','max:100'],
            'tracking_number'=>['nullable','string','max:100'],'tracking_url'=>['nullable','url:http,https','max:2000'],
            'store_name'=>['required','string','max:150'],
        ];
    }
}
