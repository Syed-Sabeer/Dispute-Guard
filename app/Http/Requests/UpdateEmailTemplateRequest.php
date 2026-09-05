<?php
declare(strict_types=1);
namespace App\Http\Requests;
class UpdateEmailTemplateRequest extends MerchantRequest
{
    public function rules(): array { return ['enabled'=>['required','boolean'],'subject'=>['required','string','max:200','not_regex:/[\r\n]/'],'body'=>['required','string','max:20000']]; }
}
