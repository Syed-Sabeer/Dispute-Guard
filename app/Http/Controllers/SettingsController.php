<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateShopSettingsRequest;
use App\Services\Email\MerchantSenderService;

class SettingsController extends MerchantController
{
    public function edit()
    {
        return $this->page('settings.edit', ['settings' => $this->shop()->settings, 'sender' => $this->shop()->emailSender()->first()]);
    }

    public function update(UpdateShopSettingsRequest $request)
    {
        $data = $request->safe()->except('templates_reviewed');
        $data['templates_reviewed_at'] = $request->boolean('templates_reviewed') ? now() : null;
        $data['onboarded_at'] = $request->boolean('templates_reviewed') && $request->filled('support_email')
            && app(MerchantSenderService::class)->eligible($this->shop()) ? ($this->shop()->settings->onboarded_at ?? now()) : null;
        $this->shop()->settings->update($data);

        return response()->json(['message' => 'Settings saved.']);
    }

    public function onboarding()
    {
        return $this->page('settings.onboarding', ['settings' => $this->shop()->settings,
            'senderReady' => app(MerchantSenderService::class)->eligible($this->shop()),
            'testSent' => $this->shop()->emailLogs()->where('type', 'test')->where('status', 'SENT')->exists()]);
    }
}
