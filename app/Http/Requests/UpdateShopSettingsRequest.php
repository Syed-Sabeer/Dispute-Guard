<?php
declare(strict_types=1);
namespace App\Http\Requests;
class UpdateShopSettingsRequest extends MerchantRequest
{
    public function rules(): array
    {
        return ['store_display_name'=>['required','string','max:150'],'support_email'=>$this->emailRules(true),'reply_to_email'=>$this->emailRules(),
            'auto_email_enabled'=>['required','boolean'],'test_mode'=>['required','boolean'],'timezone'=>['required','timezone'],'email_footer'=>['nullable','string','max:2000'],'templates_reviewed'=>['required','boolean']];
    }
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (!$this->boolean('auto_email_enabled')) { return; }
            $shop = app(\App\Services\CurrentShop::class)->get();
            if (!$this->boolean('templates_reviewed') || !$shop->emailLogs()->where('type','test')->where('status','SENT')->exists()) {
                $v->errors()->add('auto_email_enabled','Review templates and successfully send a test email before activating automation.');
            }
            if ($this->boolean('test_mode') || config('chargeguard.test_mode')) { $v->errors()->add('auto_email_enabled','Turn off test mode before activating production automation.'); }
        });
    }
}
