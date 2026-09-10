<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\CurrentShop;
use App\Services\Email\MerchantSenderService;

class UpdateShopSettingsRequest extends MerchantRequest
{
    public function rules(): array
    {
        return ['store_display_name' => ['required', 'string', 'max:150'], 'support_email' => $this->emailRules(true), 'reply_to_email' => $this->emailRules(),
            'auto_email_enabled' => ['required', 'boolean'], 'test_mode' => ['required', 'boolean'], 'timezone' => ['required', 'timezone'], 'email_footer' => ['nullable', 'string', 'max:2000'], 'templates_reviewed' => ['required', 'boolean']];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if (! $this->boolean('auto_email_enabled')) {
                return;
            }
            if (! $this->boolean('templates_reviewed')) {
                $v->errors()->add('auto_email_enabled', 'Review the email templates before activating automation.');
            }
            if ($this->boolean('test_mode') || config('chargeguard.test_mode')) {
                $v->errors()->add('auto_email_enabled', 'Turn off test mode before activating production automation.');
            }
            if (! app(MerchantSenderService::class)->eligible(app(CurrentShop::class)->get())) {
                $v->errors()->add('auto_email_enabled', 'Verify your sender email before activating automatic customer follow-ups.');
            }
        });
    }
}
